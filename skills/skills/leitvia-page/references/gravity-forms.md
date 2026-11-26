# Forms with uploads or steps: Gravity Forms

Details for `leitvia-page`. Read this when visitors send photos or files, when a request needs several steps, or for applications. `leitvia/contact-form` takes name, email and message only and stays so.

Gravity Forms is a commercial plugin: the person buys and installs it, you never upload or activate a plugin. The theme Leitvia styles its forms (themes Orbital and Gravity Forms 2.5) with the tokens of the site, light and on inverse bands, and loads that style only on pages with a form. Colors and the look of image choices picked in the form block of Gravity Forms do not apply; the token set decides.

## Create the form

- **Gravity Forms 3.1 and later** registers its own abilities. They are no tools of the MCP server `leitvia`: call them over REST with the application password or through the MCP server of Gravity Forms. They register only with MCP on (Forms, Settings, MCP, "Enable MCP") and only the tools switched on there; every tool is off by default. Read with `gravityforms/forms-list` and `gravityforms/forms-get`, build with `gravityforms/forms-create`, `gravityforms/forms-update`, `gravityforms/confirmations-create` and `gravityforms/notifications-create`; entries are `gravityforms/entries-search`, `gravityforms/entries-get`, `gravityforms/entries-count`, `gravityforms/entries-update` and `gravityforms/entries-delete`. Check that `GET /wp-json/wp-abilities/v1/abilities` lists them for the agent user before you use them; if not, the person switches on the tools you name, or you take the REST API below.
- **Otherwise** the REST API v2 of Gravity Forms with the same application password: `POST /wp-json/gf/v2/forms` with the form as JSON, `PUT /wp-json/gf/v2/forms/<id>` for changes. The person enables the REST API under Forms, Settings, REST API.
- Leitvia Pro brings form templates for inquiries and applications; its skill names them.
- `forms-create` ignores `validationSummary` and adds an admin notification of its own. Right after it, set `validationSummary` false with `forms-update` and read the form again (`forms-get`). Keep one notification to the address of the organization data: change the automatic one in `notifications` of the same update, or set its `isActive` false.
- Put the form on the page inside `leitvia/form-errors`: an `insert` edit with `gravityforms/form` (`formId` as string) as inner block ([edits](composition.md#edits)), so the summary of `leitvia/form-errors` is the only one.

## Privacy (GDPR)

Set on every form, and report each setting:

- `enableHoneypot` true and `enableSubmitSpeedCheck` true; no reCAPTCHA, hCaptcha or Turnstile add-on, they load scripts from other hosts before any consent. `leitvia/audit-site` reports an active captcha as the check `form-captcha`; it goes into your report with high severity, and only the person turns the add-on off.
- `personalData.preventIP` true: the IP address is not stored.
- `personalData.retention`: `policy` `delete` and `retain_entries_days` as the person decides (90 days for inquiries is a usual default to propose; applications as the retention policy of the person says). The person confirms the period.
- File fields: `allowedExtensions` only what the request needs (jpg, png, heic for photos; pdf for certificates), `maxFileSize` in MB (10 at most), `maxFiles` for several files.
- A note on the processing before the first field or above the submit button, with a link to the privacy policy page and the retention period; the privacy policy names the form, its purpose and the period. When the period changes, change the note in the same package. Notifications go to the address of the organization data, never to a private address the person did not name.

## Accessibility

- Every field has a visible label; required fields are marked (`isRequired`) and the form says above the first field what the mark means.
- Several steps: pagination of type `steps` with a name per step, or `percentage`; never more than five steps.
- Error messages say what to do ("Enter your phone number"), not only "This field is required."
- File fields name the allowed types and the size limit in their description.
- After building, open the page at 390 and 1440 px, send it once empty and check that the summary of `leitvia/form-errors` lists every error with a link to its field.
