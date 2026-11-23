import {
  DOCX_LOSS_REPORT_SCHEMA,
  type DiagnosticAction,
  type DiagnosticSeverity,
  type DocxProfileId,
  type LossDiagnostic,
  type LossReport,
  type SourceMarker,
} from './types'

export class DocxProfileBlockedError extends Error {
  readonly report: LossReport

  constructor(report: LossReport) {
    const reasons = report.blocking_reasons.join('; ') || 'Unsupported mandatory OOXML feature'
    super(`DOCX profile "${report.profile}" blocked: ${reasons}`)
    this.name = 'DocxProfileBlockedError'
    this.report = report
  }
}

export class LossReportBuilder {
  private readonly items: LossDiagnostic[] = []

  add(diagnostic: LossDiagnostic): void {
    this.items.push(diagnostic)
  }

  info(
    code: string,
    feature: string,
    location: string,
    action: DiagnosticAction,
    message: string,
    details?: Record<string, unknown>,
  ): void {
    this.add({ code, severity: 'info', feature, location, action, message, ...(details ? { details } : {}) })
  }

  warn(
    code: string,
    feature: string,
    location: string,
    action: DiagnosticAction,
    message: string,
    details?: Record<string, unknown>,
  ): void {
    this.add({ code, severity: 'warning', feature, location, action, message, ...(details ? { details } : {}) })
  }

  block(
    code: string,
    feature: string,
    location: string,
    message: string,
    details?: Record<string, unknown>,
  ): void {
    this.add({ code, severity: 'error', feature, location, action: 'blocked', message, ...(details ? { details } : {}) })
  }

  merge(diagnostics: readonly LossDiagnostic[]): void {
    for (const item of diagnostics) {
      this.items.push(item)
    }
  }

  all(): LossDiagnostic[] {
    return [...this.items]
  }

  build(profile: DocxProfileId, sourceMarker: SourceMarker | null = null): LossReport {
    let info = 0
    let warning = 0
    let error = 0
    const blockingReasons: string[] = []

    for (const item of this.items) {
      const sev: DiagnosticSeverity = item.severity
      if (sev === 'info') {
        info += 1
      } else if (sev === 'warning') {
        warning += 1
      } else {
        error += 1
        blockingReasons.push(`${item.code} (${item.location}): ${item.message}`)
      }
    }

    return {
      schema: DOCX_LOSS_REPORT_SCHEMA,
      profile,
      source_marker: sourceMarker,
      blocked: error > 0,
      blocking_reasons: blockingReasons,
      diagnostics: [...this.items],
      summary: {
        info,
        warning,
        error,
      },
    }
  }
}
