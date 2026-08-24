# KDNA Charts

An editorial charting plugin for WordPress. It exists to produce the kind
of chart that belongs inside an article, a chart that makes an argument,
rather than the kind that belongs on a dashboard.

- **Slug:** `kdna-charts`
- **Version:** 1.0.0
- **Requires:** WordPress 6.0, PHP 8.0
- **Build stage:** 3 of 13, scale and axis engine

A full README, written for someone installing the finished plugin,
arrives at Stage 13. What follows is the state of the build.

## What is built

Nothing draws yet. The first visible output arrives at Stage 4.

| Piece | Stage | State |
| --- | --- | --- |
| Plugin skeleton, constants, text domain | 1 | Done |
| `kdna_chart` custom post type, private, title only | 1 | Done |
| Meta registration for the whole chart definition | 1 | Done |
| JSON encoded storage and content hash | 1 | Done |
| Top level KDNA Charts admin menu | 1 | Done |
| All Charts library list, with type, engine, point and annotation columns | 1 | Done |
| Elementor hooks registered at file load time | 1 | Done, widget itself at Stage 11 |
| Chart definition schema, version 1 | 2 | Done |
| Validator, with a report of what it discarded, dropped and repaired | 2 | Done |
| Import screen, file upload and paste | 2 | Done |
| `docs/chart-schema.md`, printed on the import screen with a copy button | 2 | Done |
| Scale engine: plot area, domains, ticks, path geometry | 3 | Done |
| Diagnostic dump at `?kdna_debug=1` | 3 | Done |
| Add New screen | 8 | Placeholder, real type chooser at Stage 8 |

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

## The schema and the importer

`KDNA_Charts_Schema` is the single source of truth for what a chart
definition may contain. Every other part of the plugin asks it rather
than deciding for itself: the importer validates against it, Add New
builds its starter data from it, and the renderers will read their
option defaults from it. `docs/chart-schema.md` is the prose mirror of
it, and the file that gets pasted into a Claude conversation.

The importer's governing rule, from section 4.4 of the brief, is that
an import should almost never fail. Exactly two things are fatal, and
both mean the file is not a KDNA Charts definition at all:

- no `kdna_chart` key, so the file does not say which schema it was
  written for
- no usable `type`, so there is no chart to draw

Everything else is handled and reported, in four buckets, because the
four outcomes are genuinely different:

| Bucket | Meaning |
| --- | --- |
| Discarded | A key the schema does not know. Removed. |
| Dropped | A known key whose value was unusable. Removed. |
| Repaired | A known key whose value was fixable. Kept, changed. |
| Ignored | A valid key this chart type will not draw. Kept as it is. |

That last bucket exists because of section 3.1's rule that switching
engine never destroys data. The same reasoning applies to chart type: a
pie chart cannot draw a marker, but it should still hand the marker
back if the chart is later changed to a line. Nothing is deleted for
being undrawable, it is reported as kept but not drawn.

Findings are grouped before they are shown, so forty numeric strings
read as one repair with a count of forty rather than forty sentences,
and `series[0].colour` and `series[3].colour` collapse into one finding
about `series[].colour`.

## The scale engine

`KDNA_Charts_Scale` is the maths every plotted chart type depends on.
It emits nothing: no markup, no echo, no enqueue. Everything it returns
is a number, a list of numbers or a path string, and the renderer
decides what to do with it.

The canvas is a fixed 1000 units wide, with the height following from
the aspect ratio. The SVG carries a viewBox and width 100 per cent, so
those units are proportions rather than pixels and a chart scales
fluidly with no JavaScript at all. SVG counts its y axis downward and
charts count upward, and that inversion lives in one method here rather
than being remembered in twenty places.

Three decisions worth knowing about.

**Nothing is clamped by default.** A value outside the domain maps to a
coordinate outside the plot area, in the padding. This is on purpose:
the collagen chart's note sits at y 111 against a domain that stops at
108, because the note belongs above the plot. Clamping would drop it
onto the frame. Annotations are also kept out of domain inference, for
the same reason: a note placed above the data would otherwise stretch
the axis and flatten the line it is annotating.

**Smoothing is not allowed to invent data.** A Catmull Rom spline
passes through every point it is given, which is the property a chart
needs. But it can bulge past them, and on a chart that is a lie: a
gentle rise that suddenly steepens gets drawn dipping below its own
starting value first. So where three consecutive points move in one
direction, the control points are held inside the span they belong to.
Genuine peaks still curve through their apex, because the clamp only
applies where the data itself is monotone.

**Padding adapts to the labels.** An axis with a title reserves room
for it, an axis without one gets the space back, rather than every
chart carrying a margin for text that may not be there.

## The diagnostic dump

Add `?kdna_debug=1` to any admin URL with a chart id, or to a chart's
own edit screen URL, and the plugin prints the computed geometry
instead of the page: canvas, padding, plot area, both domains and where
each came from, every tick with its SVG position, and every data point
beside the coordinate it maps to, with the path string built from it.

- `wp-admin/admin.php?kdna_debug=1&chart=123`
- `wp-admin/post.php?post=123&action=edit&kdna_debug=1`
- add `&format=json` for the raw payload
- with no chart id, it lists the library so one can be picked

Administrators only. A chart definition is not secret, but a debug
endpoint anybody can call on any post id is a fishing tool.

## File structure so far

```
kdna-charts/
├── kdna-charts.php
├── includes/
│   ├── class-kdna-charts-plugin.php
│   ├── class-kdna-charts-cpt.php
│   ├── class-kdna-charts-schema.php
│   ├── class-kdna-charts-import.php
│   ├── class-kdna-charts-data.php
│   ├── class-kdna-charts-scale.php
│   └── class-kdna-charts-admin.php
├── templates/
│   ├── admin-add-new-placeholder.php
│   └── admin-editor-import.php
├── assets/
│   └── css/
│       └── kdna-admin.css
├── docs/
│   └── chart-schema.md
└── README.md
```

## Testing Stage 3

Nothing new is visible on the front end. The diagnostic is how this
stage is checked.

1. Import `collagen-decline-chart.json` if it is not already in the
   library, and note its post ID.
2. Visit `wp-admin/admin.php?kdna_debug=1&chart=ID`.
3. Confirm the viewBox reads `0 0 1000 562.5`, the aspect ratio 16:9.
4. Confirm the plot area is 886 wide and 448.5 high, starting at x 82.
   The left and bottom padding are larger than the defaults because
   both axes carry titles.
5. Confirm both domains say **stated by the chart**, x from -8 to 20 and
   y from 45 to 108.
6. Confirm five x ticks and three y ticks, all marked **stated**, with
   Year 0 strong and 10 and 15 muted, exactly as the file asks.
7. Confirm the y tick at 100 has a solid rule and the other two dotted.
8. Confirm the two segments list eleven points between them, each with
   an SVG coordinate, and a path string beneath.
9. Open a chart with no `min`, `max` or `ticks` and confirm the domains
   say **inferred from the data** and the ticks say **generated**, at
   round numbers.
10. Add `&format=json` and confirm the same payload comes back as JSON.
11. Log in as an editor rather than an administrator and confirm the
    flag does nothing.

## Testing Stage 2

1. Open **KDNA Charts > Import**. Confirm the file field, the paste box
   and the two copy buttons appear.
2. Import `collagen-decline-chart.json`. It should create a draft chart
   and land on its edit screen with a green summary reading that
   nothing was ignored, dropped or repaired, with 11 data points and 6
   annotations.
3. Import the same file again. The summary should say it is the same
   chart as the one already in the library, and link to it.
4. Delete the `kdna_chart` line from a copy of the file and import it.
   The import should fail, return to the Import screen, and say exactly
   what is missing.
5. Change `"kdna_chart": 1` to `2` and import. It should fail with a
   version message.
6. Change `"type"` to `"sankey"` and import. It should fail and list the
   types the schema allows.
7. Add a nonsense key such as `"theme": "dark"` and import. It should
   succeed, with an amber summary naming the key it left out.
8. Break a few points, for example `[5]` or `[10, "sixty"]`, and import.
   The chart should still arrive, minus those points, with a count.
9. Paste the same JSON wrapped in triple backtick code fences. It should
   import, and say it stripped the fences.
10. Upload something that is not JSON, for example a .png renamed to
    .json. It should refuse and explain why.
11. Press **Copy the full authoring prompt** and paste it somewhere. It
    should contain the prompt, the rules and the whole schema reference.

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
