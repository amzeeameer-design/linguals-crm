# Current milestone — admin and applicant frontend

The responsive SaaS frontend and interactive standalone preview are implemented. Admin and applicant screens cover the agreed application → review → invitation → KYC → NDA → activation journey.

The primary admin entry point now opens talent discovery. It includes 13 filter groups, searchable custom-field conditions, directional and service-bound matching, saved views, shortlists, comparison, configurable tables/cards and filtered export. The payload includes structured language, pair, domain, rate and background data. See TALENT_DISCOVERY.md in the package root.

Protected Laravel entry points: `/workspace` for administrators, `/portal` for applicants. Admin data, existing review actions and applicant-specific application/document metadata are integrated in source. Full runtime integration remains unverified; see VALIDATION.md.

Upcoming backend work:
1. Applicant draft and profile writes, structured service details and related records.
2. Invitation tokens, account provisioning, reliable email delivery and message history.
3. Private document uploads, version replacement and storage/retention rules.
4. Human KYC review endpoints and version-specific AI assessment (provider and mandatory document list pending).
5. Approved NDA template, signing service and activation rules.
6. Account security/preferences, workspace settings and real form analytics.

Standalone interactions simulate these services and are clearly marked as preview operations. No legal or authenticity assurances are implied.
