# leitvia/set-organization

Generated from Leitvia Studio 1.3.62. Do not edit. Group: Set up. How to call abilities, hashes, autonomy and rejections: [agent handbook](../README.md).

**Set organization details.** Sets the details of the organization behind the site: kind of business (type, Organization or a type of LocalBusiness; type falls back to Organization), a description of what it does (description, at most 500 characters, printed as description of the Organization or LocalBusiness), a logo for structured data (logo, ID of a raster image from leitvia/list-media of at least 112 pixels each way, 0 for the logo of the header; search engines show it on white, so a header with a light logo on a dark background can hand them a dark one), the page with the editorial guidelines (editorial_page, ID of a page, 0 clears it; printed as publishingPrinciples of the organization, also as publisher of news posts, and linked from the author box of the theme; without it the only published page with the page kind editorial counts, with several none), address, phone, email, founding date (founding_date, YYYY, YYYY-MM or YYYY-MM-DD), founder or owner (founder, a person, printed as schema.org founder), people (people, profiles with name, job_title, description, image as media ID, same_as with https profiles, knows_about with topics, page as the ID of the page about the person; the list replaces the saved one, at most 10; Leitvia Studio prints each as a Person with one @id for the founder of the same name, the author of articles with the same author name and the main entity of a ProfilePage on its page), the copyright line of the footer (copyright_since, the first year as YYYY, and copyright_holder, the name after the years; the theme Leitvia then shows the first year, a dash, the current year and the holder), area served (area_served, places), opening hours or service times (opening_hours, rows of days with opens and closes as HH:MM; a local business prints them as openingHoursSpecification, every type as hoursAvailable of the ContactPoint), what phone and email are for (contact_type, contactType of the ContactPoint that every type with a phone or an email gets, customer service by default), profiles on other sites (same_as, https addresses), the rating on a review site (aggregate_rating: value 1 to 5, count of reviews, source_url where it is shown, confirmed true only after a person checked it there; printed as aggregateRating and allowed as a star in SEO titles only when confirmed, with count and source; null removes it) and how the default texts visitors read address them (address: informal, the default, or formal, in German du or Sie; it covers messages, hints, confirmations and emails of forms and the dialog of the privacy settings, also those of add-ons that register their texts; a site language such as de_DE_formal is formal anyway). Leitvia Studio prints them as structured data on the front page and as publisher of posts while no SEO plugin is active; the name stays that of the site, the logo too unless logo names one. Only the fields you send change, an empty string or an empty list clears one. leitvia/get-seo returns the current values. base_hash is empty. Use only facts the person gave or the site shows; never guess opening hours. Like every setting it waits in a change package for a person.

MCP tool `leitvia-set-organization`, REST POST. Annotations: readonly false, destructive true, idempotent false.

| Input | Type | Required | Description |
|---|---|---|---|
| `type` | one of `Organization`, `LocalBusiness`, `HomeAndConstructionBusiness`, `GeneralContractor`, `Electrician`, `Plumber`, `HVACBusiness`, `RoofingContractor`, `HousePainter`, `AutoRepair`, `Restaurant`, `Store`, `HealthAndBeautyBusiness`, `LodgingBusiness` |  |  |
| `description` | string, max length `500` |  | What the organization does, in one or two sentences as the site says it. |
| `logo` | integer, min `0` |  | ID of a raster image from leitvia/list-media as logo in structured data, 0 for the logo of the header. |
| `editorial_page` | integer, min `0` |  | ID of the page with the editorial guidelines from leitvia/list-content, 0 to clear the setting. |
| `street` | string |  |  |
| `postal_code` | string |  |  |
| `city` | string |  |  |
| `country` | string, pattern `^([A-Z]{2})?$` |  |  |
| `phone` | string |  |  |
| `email` | string |  |  |
| `contact_type` | string, max length `60` |  | What phone and email are for, printed as contactType of the ContactPoint, such as customer service, sales or technical support; empty means customer service. |
| `founding_date` | string, pattern `^(\d{4}(-(0[1-9]\|1[0-2])(-(0[1-9]\|[12]\d\|3[01]))?)?)?$` |  |  |
| `founder` | string, max length `200` |  |  |
| `people` | array of object { name\*, job_title, description, image, same_as, knows_about, page }, max items `10` |  | The people behind the site, such as the founder and the authors; the list replaces the saved one, an empty list removes all. The name links a profile to the founder and to authors of the same name. |
| `copyright_since` | string, pattern `^(\d{4})?$` |  |  |
| `copyright_holder` | string, max length `200` |  |  |
| `area_served` | array of string, max items `50` |  |  |
| `opening_hours` | array of object { days\*, opens\*, closes\* }, max items `14` |  |  |
| `same_as` | array of string, pattern `^https://`, max items `50` |  |  |
| `address` | one of `informal`, `formal` |  | informal (du) or formal (Sie). |
| `aggregate_rating` | object { value\*, count\*, source_url\*, confirmed\* } |  |  |
| `base_hash` | string | yes | Hash of the target as last read; empty for a new object. |
| `package` | string, max length `64` |  | Key of the task: a write with a key always goes into the change package of that key, even where the autonomy would allow it live, and operations with the same key share that package. |

Output: the write result. Its fields: [Write results](../README.md#write-results).
