# Laravel API Starter Kit

A Laravel **API-only** starter kit: token-based authentication and account
management as plain, readable Laravel code. No front-end, no Vite, no Fortify —
just controllers, Form Requests, API Resources, and a green test suite.

**Disclaimer**: This project is a port of laraveldaily/api-starter-kit to the Action Pattern,
featuring the following architectural additions:
- Action Pattern with readonly DTOs
- Domain Exceptions with a global handler
- Full email verification flow
- Email change with destination verification
- OWASP protections: timing attack, replay attack, user enumeration
- Two-layer test coverage (Unit + Feature)