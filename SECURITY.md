# Security policy

Vaulter Editor Pro is a proprietary package set distributed through a private channel.
Security fixes target `main` and are shipped in the next private release.

Report vulnerabilities privately to Dmitry Vostrikov at **dv.vostrikov@gmail.com**. Do not
put credentials, personal data, exploit details or customer documents in issues. Send a
minimal redacted reproduction (for DOCX issues, a synthetic document that triggers the
problem), the affected package and version, and the impact.

Particularly relevant areas are the DOCX import path (ZIP and XML parsing, size and entry
quotas, external relationships), authorization of review and exchange endpoints, and the
handling of stored blobs.

Target acknowledgement is within three working days and an initial assessment within ten
working days. These are response targets, not guaranteed remediation deadlines.

Please test only systems you own or are allowed to assess, minimize data access, and stop if
customer data is exposed.
