from __future__ import annotations

from pathlib import Path

from geo_monitor_poc.adapters import create_adapter
from geo_monitor_poc.browser import fetch_with_action, open_platform_session
from geo_monitor_poc.config import DEFAULT_PROBE_TIMEOUT_MS
from geo_monitor_poc.exceptions import CaptchaRequiredError
from geo_monitor_poc.models import AccountConfig, LoginStatus, ProbeEvidence, ProbeResult, ProbeStatus
from geo_monitor_poc.notify import emit_captcha_alert
from geo_monitor_poc.utils import now_ms


def _failed_result(
    account: AccountConfig,
    *,
    prompt_id: str,
    prompt_text: str,
    started_at_ms: int,
    error_message: str,
    selector_version: str,
    login_status: LoginStatus = LoginStatus.UNKNOWN,
) -> ProbeResult:
    """
    构造失败探测结果。

    @param account 账号配置
    @param prompt_id 问题 ID
    @param prompt_text 问题文本
    @param started_at_ms 开始毫秒时间戳
    @param error_message 错误信息
    @param selector_version selector 版本
    @param login_status 登录态
    @return 失败结果
    """
    return ProbeResult(
        platform=account.platform,
        account_id=account.id,
        prompt_id=prompt_id,
        prompt_text=prompt_text,
        status=ProbeStatus.FAILED,
        login_status=login_status,
        error_message=error_message,
        duration_ms=now_ms() - started_at_ms,
        meta={"selector_version": selector_version},
    )


def _captcha_result(
    account: AccountConfig,
    *,
    prompt_id: str,
    prompt_text: str,
    started_at_ms: int,
    evidence: ProbeEvidence,
    stage: str,
    selector_version: str,
    error_message: str = "",
) -> ProbeResult:
    """
    构造验证码阻断结果（生产无头模式用）。

    @param account 账号配置
    @param prompt_id 问题 ID
    @param prompt_text 问题文本
    @param started_at_ms 开始毫秒时间戳
    @param evidence 已保存证据
    @param stage 验证码出现阶段
    @param selector_version selector 版本
    @param error_message 错误说明
    @return 验证码结果
    """
    return ProbeResult(
        platform=account.platform,
        account_id=account.id,
        prompt_id=prompt_id,
        prompt_text=prompt_text,
        status=ProbeStatus.CAPTCHA,
        login_status=LoginStatus.CAPTCHA,
        evidence=evidence,
        error_message=error_message or f"检测到验证码（阶段: {stage}）",
        duration_ms=now_ms() - started_at_ms,
        meta={
            "selector_version": selector_version,
            "captcha_stage": stage,
        },
    )


def run_probe(
    account: AccountConfig,
    *,
    prompt_id: str,
    prompt_text: str,
    evidence_dir: Path,
    headless: bool = True,
    skip_login_check: bool = False,
    interactive: bool = False,
) -> ProbeResult:
    """
    对单个平台账号执行一次问题探测。

    Scrapling 在 fetch 结束时会关闭 Playwright，因此所有 page 操作必须在 page_action 内完成。

    @param account 账号配置
    @param prompt_id 问题 ID
    @param prompt_text 问题文本
    @param evidence_dir 证据输出目录
    @param headless 是否无头模式
    @param skip_login_check 是否跳过登录态拦截（访客模式平台如豆包）
    @param interactive 是否在验证码等场景暂停等待人工处理
    @return 探测结果
    """
    adapter = create_adapter(account)
    adapter.interactive = interactive
    state: dict[str, object] = {"result": None}
    started_at_ms = now_ms()

    def page_action(page) -> None:
        try:
            page.wait_for_timeout(1_000)
            adapter.wait_for_human_intervention(page, interactive=interactive, stage="页面加载后")
            adapter.prepare_page(page)
            adapter.wait_for_human_intervention(page, interactive=interactive, stage="输入前")
            login_status = adapter.detect_login_status(page)
            if (
                not skip_login_check
                and login_status in {
                    LoginStatus.NOT_LOGGED_IN,
                    LoginStatus.CAPTCHA,
                    LoginStatus.BLOCKED,
                }
            ):
                evidence = adapter.save_evidence(page, evidence_dir, prompt_id)
                status = (
                    ProbeStatus.CAPTCHA
                    if login_status == LoginStatus.CAPTCHA
                    else ProbeStatus.NEEDS_LOGIN
                )
                captcha_stage = "页面加载后" if login_status == LoginStatus.CAPTCHA else ""
                state["result"] = ProbeResult(
                    platform=account.platform,
                    account_id=account.id,
                    prompt_id=prompt_id,
                    prompt_text=prompt_text,
                    status=status,
                    login_status=login_status,
                    evidence=evidence,
                    error_message=(
                        "检测到验证码，需人工处理 profile"
                        if login_status == LoginStatus.CAPTCHA
                        else "登录态无效，请先执行 login 命令"
                    ),
                    duration_ms=now_ms() - started_at_ms,
                    meta={
                        "selector_version": adapter.selectors.selector_version,
                        **({"captcha_stage": captcha_stage} if captcha_stage else {}),
                    },
                )
                return

            error_message = ""
            try:
                if not adapter.fill_prompt(page, prompt_text):
                    raise RuntimeError(f"{adapter.platform.value} 未找到输入框")
                adapter.click_send(page)
                adapter.wait_for_human_intervention(page, interactive=interactive, stage="发送问题后")
                adapter.wait_for_answer(page)
            except CaptchaRequiredError as exc:
                evidence = adapter.save_evidence(page, evidence_dir, f"{prompt_id}_captcha")
                state["result"] = _captcha_result(
                    account,
                    prompt_id=prompt_id,
                    prompt_text=prompt_text,
                    started_at_ms=started_at_ms,
                    evidence=evidence,
                    stage=exc.stage,
                    selector_version=adapter.selectors.selector_version,
                    error_message=str(exc),
                )
                return
            except Exception as exc:  # noqa: BLE001
                error_message = str(exc)
                adapter.save_evidence(page, evidence_dir, f"{prompt_id}_error")

            state["result"] = adapter.build_probe_result(
                prompt_id=prompt_id,
                prompt_text=prompt_text,
                page=page,
                evidence_dir=evidence_dir,
                started_at_ms=started_at_ms,
                error_message=error_message,
            )
        except CaptchaRequiredError as exc:
            evidence = adapter.save_evidence(page, evidence_dir, f"{prompt_id}_captcha")
            state["result"] = _captcha_result(
                account,
                prompt_id=prompt_id,
                prompt_text=prompt_text,
                started_at_ms=started_at_ms,
                evidence=evidence,
                stage=exc.stage,
                selector_version=adapter.selectors.selector_version,
                error_message=str(exc),
            )
        except Exception as exc:  # noqa: BLE001
            state["result"] = _failed_result(
                account,
                prompt_id=prompt_id,
                prompt_text=prompt_text,
                started_at_ms=started_at_ms,
                error_message=str(exc),
                selector_version=adapter.selectors.selector_version,
            )

    try:
        with open_platform_session(
            account,
            headless=headless,
            timeout_ms=DEFAULT_PROBE_TIMEOUT_MS,
        ) as session:
            fetch_with_action(
                session,
                adapter.selectors.chat_url,
                page_action,
                wait_selector=adapter.selectors.page_ready_selector,
                wait_ms=5_000 if interactive else 2_000,
            )
    except Exception as exc:  # noqa: BLE001
        return _failed_result(
            account,
            prompt_id=prompt_id,
            prompt_text=prompt_text,
            started_at_ms=started_at_ms,
            error_message=str(exc),
            selector_version=adapter.selectors.selector_version,
        )

    result = state.get("result")
    if isinstance(result, ProbeResult):
        if result.status == ProbeStatus.CAPTCHA:
            stage = str(result.meta.get("captcha_stage", ""))
            emit_captcha_alert(result, stage=stage)
        return result

    return _failed_result(
        account,
        prompt_id=prompt_id,
        prompt_text=prompt_text,
        started_at_ms=started_at_ms,
        error_message="page_action 未产生结果",
        selector_version=adapter.selectors.selector_version,
    )
