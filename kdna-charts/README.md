# KDNA Charts

An editorial charting plugin for WordPress. It exists to produce the kind
of chart that belongs inside an article, a chart that makes an argument,
rather than the kind that belongs on a dashboard.

- **Slug:** `kdna-charts`
- **Version:** 1.0.0
- **Requires:** WordPress 6.0, PHP 8.0
- **Build stage:** 1 of 13, foundation and data model

A full README, written for someone installing the finished plugin,
arrives at Stage 13. What follows is the state of the build.

## What Stage 1 delivers

The skeleton and the data model. Nothing draws yet.

| Piece | State |
| --- | --- |
| Plugin skeleton, constants, text domain | Done |
| `kdna_chart` custom post type, private, title only | Done |
| Meta registration for the whole chart definition | Done |
| JSON encoded storage and content hash | Done |
| Top level KDNA Charts admin menu | Done |
| All Charts library list, with type, engine, point and annotation columns | Done |
| Add New screen | Placeholder, real type chooser at Stage 8 |
| Elementor hooks registered at file load time | Done, widget itself at Stage 11 |

## Data model

A chart definition is the interchange shape from section 4 of the project
brief. It is stored across a set of post meta keys, one per top level
schema key.

Scalar keys are stored plainly:

| Meta key | Holds |
| --- | --- |
| `_kdna_chart_type` | `line`, `area`, `bar`, `column`, `pie`, `donut`, `stat` |
| `_kdna_chart_engine` | `svg`, `chartjs`, or empty meaning inherit the site default |
| `_kdna_chart_source` | Attribution line |
| `_kdna_chart_caption` | Caption |
| `_kdna_chart_schema_version` | Schema version, 1 |
| `_kdna_chart_content_hash` | md5 of the meaningful content |

Structured keys are stored JSON encoded:

| Meta key | Holds | Shape |
| --- | --- | --- |
| `_kdna_chart_options` | Type specific rendering options | object |
| `_kdna_chart_axes` | `x` and `y` definitions | object |
| `_kdna_chart_series` | The data | list |
| `_kdna_chart_markers` | Event lines | list |
| `_kdna_chart_points` | Emphasised data points | list |
| `_kdna_chart_callouts` | Large number annotations | list |
| `_kdna_chart_notes` | Small free floating labels | list |
| `_kdna_chart_style_overrides` | Per chart style overrides | object |

### Why JSON rather than serialised arrays

KDNA Tables stores its data as PHP arrays in meta. Charts store JSON
strings instead, for one reason: chart data is nested lists of numbers,
and a definition often arrives as a JSON file authored in a Claude
conversation. JSON round trips those exactly as written, including which
numbers were integers, and hands the same bytes back when a chart is
exported again.

One trap comes with that choice. `update_post_meta()` runs `wp_unslash()`
over a value before storing it, and JSON escapes quotes and backslashes
with backslashes, so writing a raw JSON string would store it with its
escapes eaten and its labels broken. Every write therefore goes through
`KDNA_Charts_CPT::update_json_meta()`, which slashes first so the unslash
restores exactly what was encoded. Nothing should write these meta keys
directly.

### Sanitising

`KDNA_Charts_CPT::sanitize_structure()` guarantees storage safety only. It
walks the decoded structure, text sanitises strings, keeps numbers,
booleans and nulls, drops objects and non finite floats, runs keys
through `sanitize_key`, and stops at twelve levels deep. A null is
preserved, because a null y value is a legitimate gap in a series.

Whether the structure means anything as a chart is a separate question,
answered by the schema validator at Stage 2. Storage safety and schema
validity are deliberately different jobs in different classes.

### Content hash

`KDNA_Charts_CPT::content_hash()` strips internal ids, sorts object keys
while leaving list order alone, and hashes the result. Two charts a
reader would call identical hash identically, whatever ids their series
carry. The importer uses it at Stage 2 to notice a re-import of the same
file, and the renderers use it later as a cache key.

## File structure so far

```
kdna-charts/
├── kdna-charts.php
├── includes/
│   ├── class-kdna-charts-plugin.php
│   ├── class-kdna-charts-cpt.php
│   ├── class-kdna-charts-data.php
│   └── class-kdna-charts-admin.php
├── templates/
│   └── admin-add-new-placeholder.php
├── assets/
│   └── css/
│       └── kdna-admin.css
└── README.md
```

## Testing Stage 1

1. Upload the ZIP at Plugins > Add New > Upload Plugin, and activate it.
2. Confirm no errors or notices appear on activation.
3. Confirm **KDNA Charts** appears in the admin sidebar, with **All
   Charts** and **Add New** beneath it.
4. Open All Charts. The list is empty, with Type, Engine, Data points,
   Annotations and Shortcode columns.
5. Open Add New, pick a type, and confirm a chart entry is created and
   appears in the list with the right type pill and a point count.
6. Confirm the Engine column reads "SVG, site default" until a chart sets
   its own engine.
7. Use the Duplicate row action and confirm a copy appears as a draft.
8. Visit `post-new.php?post_type=kdna_chart` directly and confirm it
   redirects to the Add New screen rather than an empty editor.
9. Open dev tools and confirm no JavaScript console errors.

The chart edit screen shows a title field and nothing else at this stage.
That is correct: the post type supports title only, and the data editor
arrives at Stage 8.
