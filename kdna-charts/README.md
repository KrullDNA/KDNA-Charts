# KDNA Charts

An editorial charting plugin for WordPress. It exists to produce the kind
of chart that belongs inside an article, a chart that makes an argument,
rather than the kind that belongs on a dashboard.

- **Slug:** `kdna-charts`
- **Version:** 1.0.0
- **Requires:** WordPress 6.0, PHP 8.0
- **Build stage:** 6 of 13, SVG renderer, bar and column

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
| SVG renderer, line and area, with the gradient fill | 4 | Done |
| Preview meta box on the chart edit screen | 4 | Temporary, replaced at Stage 8 |
| Annotation layer: markers, points, callouts, leaders, notes | 5 | Done |
| Label collision avoidance | 5 | Done |
| SVG renderer, bar and column, grouped and stacked | 6 | Done |
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

## The renderer

Charts are rendered in PHP, so the markup arrives inside the page HTML
and needs no JavaScript to appear. Every mark is a real DOM element
with real classes.

**Not one colour, stroke width or font size is written into the
markup.** That is the whole architecture rather than a tidiness rule.
Because the renderer emits geometry and classes only, the Stage 9
cascade can change how every chart on a site looks by setting custom
properties on a wrapper, with nothing re-rendering and no chart data
touched. A chart that says nothing about its own colours inherits the
site palette automatically, because an unset property falls through to
the fallback in `assets/css/kdna-charts.css`.

The one apparent exception is `fill="url(#gradient)"` on an area path.
That is a reference to a definition, not a colour: the stops inside the
gradient read theirs from custom properties like everything else.

There is a test that asserts this, listing every attribute that could
carry an appearance value and failing if any of them appears.

### Where the layout decisions live

**Text is positioned by baseline, not by arithmetic.** Font sizes are
in CSS, so PHP does not know how tall a label is and cannot offset one
by half its height. Alignment is done with `text-anchor` and
`dominant-baseline`, which resolve at paint time when the size is known.

**Padding is sized from the labels.** An edge with no labels and no
title keeps the minimum; an edge carrying a long y label reserves room
for it. That means the tick values have to be settled before the
padding, the padding before the plot area, and the tick positions after
the plot area. The scale engine cuts that loop by separating what a
tick says from where it sits.

**Axis titles anchor to the canvas edge**, not a fixed distance beyond
the tick labels. The canvas edge is a known point; the far side of a
label is not, because PHP cannot measure text the browser has not
drawn. Anchoring to the plot is what makes a title collide with its
labels the moment a site raises the label size.

**The one place PHP and CSS have to agree** is how much room a label
needs. The padding reserves for a label at 24 user units, which is the
largest size the shipped stylesheet grows one to in a narrow container.
At the 18 unit default there is room to spare. A site that raises the
label size past 24 has to raise the padding with it, which is what the
padding controls at Stage 9 are for.

**Charts respond to their own width, not the window's.** Text inside a
viewBox scales with the chart, so a chart at half width has labels at
half size. Container queries put the size back based on how wide the
chart actually is, because a chart in a narrow sidebar of a wide page
has exactly the same problem as a chart on a phone. Viewport media
queries are kept first as a fallback for anything without container
query support.

### The area fill

One fill per series, built from all its segments joined, rather than
one per segment. A line made of a dotted projection and a solid
measurement fills as a single shape with no seam at the join, while
each segment keeps its own line character. Where two segments share an
endpoint the repeated point is dropped.

## The annotation layer

The editorial vocabulary, and the reason this plugin exists rather than
a Chart.js wrapper. Markers with headings, emphasised points with
labels, large number callouts with leader lines and span brackets, and
free floating notes.

Every other charting plugin makes these somebody's job to position by
hand in pixel coordinates. Here they are placed by the renderer, from a
definition that says what a callout means and what it points at, never
where it should sit.

### Two problems the layer has to solve

**PHP cannot measure text.** Font sizes live in CSS as custom
properties, and there is no text metric server side, so every box is an
estimate from a character count and an assumed size. The assumed sizes
match the stylesheet's defaults exactly and sit in one constant so the
two can be checked against each other. Bold text gets a wider figure
than regular, because the difference is enough to make a heading
reserve less room than the words drawn in it.

**Annotations collide.** A note and a marker heading both want the
space above the plot. So nothing is placed blindly:

- Labels whose position the author fixed get nudged clear, trying the
  preferred direction, then the opposite one. Notes may also step
  sideways; marker headings may not, because sliding one along the top
  detaches it from the line it names.
- Callouts carry a leader line and can therefore sit anywhere and still
  be understood. That freedom is what lets them be *placed* rather than
  nudged: eight directions at two distances are scored on what each
  would cover, and the best wins. Covering the line the callout is
  describing is the worst outcome, covering another annotation nearly
  as bad, and distance from the anchor a mild cost that breaks ties.
- The axis tick labels are seeded into the occupied list before any
  annotation is placed, so nothing lands on a tick.

**Nothing is ever dropped for want of space.** A label that cannot find
clear air is drawn in the least bad position, because a missing
annotation is a missing argument and silence is the worst outcome.

### Layer order

Marker lines are drawn *under* the data. A dashed rule over the series
would cut through the very shape the reader is following. Everything
else in the layer is drawn last, over everything, because a callout
covered by a gridline is a callout nobody reads.

### Span brackets

A callout anchored to a span draws a bracket: a rule standing off the
two anchor points with a tick turned in at each end, and a stem from
its middle to the figure. The bracket takes the perpendicular facing
the callout, so the stem never crosses the span it is bracketing. That
is what makes a figure read as describing a range rather than a moment.

### The description carries the argument

The accessible description names the turning points, the callout
figures and the notes, not just the shape of the line. Without it a
screen reader hears that a line fell from 108 to 52 and never learns
that thirty per cent of it went in the first five years.

## Bar and column

One body of code draws both. The scale settles which screen direction
each axis runs along, and the renderer is written in terms of the
category axis and the value axis rather than of x and y. Turning a
chart on its side then costs nothing, and the two orientations cannot
drift apart, because there is only one of them.

**The axes keep their names whichever way the bars point.** `x` is
always the category axis and `y` always the value axis. That is what
lets a chart switch between bar and column without its definition being
rewritten, and it is the same reasoning that keeps annotations stored
when a type cannot draw them.

**One rule settles almost everything else:** a gridline, or a marker,
is drawn perpendicular to the axis it belongs to. Value gridlines on a
column chart run across; on a bar chart the same gridlines run down.
Neither the gridline code nor the marker code mentions bars or columns.
A `horizontal` marker at `y: 40` draws across a column chart and down a
bar chart, and in both cases it sits at the value 40.

### Details worth knowing

- **Stacked charts are measured by their totals.** An axis inferred
  from the largest single value would run out partway up the tallest
  stack. Positives and negatives total apart, because they stack in
  opposite directions from the baseline.
- **Only the exposed end of a bar is rounded.** Rounding the end on the
  baseline would lift the bar off it, and rounding the join between two
  stacked segments would leave a notch. On a stack, only the outermost
  segment in each direction takes the radius.
- **Emphasis is only put on a bar when the definition asks for it.** An
  always present modifier would always win, and the series tones would
  never get a look in. This is the absent means inherit rule applied
  one level down.
- **A chart with several series takes tones rather than emphasis.** One
  series says what it means through emphasis; several have to be told
  apart first. Explicit emphasis still overrides the tone.
- **A bar too short to hold its figure puts it outside instead.** PHP
  cannot measure text, so this is a guess, but a number half hanging
  off the end of its own bar is worse than one sitting beyond it.
- **Value labels are drawn after every bar**, so an inside label on a
  stacked chart cannot vanish under the segment above it.
- **Bars are obstacles for the annotation layer**, the way the line is
  on a line chart, so a callout is never dropped on one.

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
│   ├── class-kdna-charts-renderer.php
│   ├── class-kdna-charts-renderer-svg.php
│   ├── class-kdna-charts-annotations.php
│   ├── class-kdna-charts-editor.php
│   └── class-kdna-charts-admin.php
├── templates/
│   ├── admin-add-new-placeholder.php
│   ├── admin-editor-import.php
│   ├── render-caption.php
│   ├── render-source.php
│   └── render-placeholder.php
├── assets/
│   └── css/
│       ├── kdna-admin.css
│       └── kdna-charts.css
├── docs/
│   └── chart-schema.md
└── README.md
```

## Testing Stage 6

1. Import or build a column chart with categories and one series.
   Confirm the bars sit on the baseline with the categories labelled
   beneath and the values down the left.
2. Change its type to `bar`. Everything should turn on its side with no
   other edit: categories down the left, values along the bottom.
3. Add two more series. Confirm they group side by side within each
   category and take three distinct tones.
4. Set `arrangement` to `stacked`. Confirm the segments abut exactly,
   the axis grows to hold the totals, and only the top segment is
   rounded.
5. Set `value_labels` to `above`, then `inside`. Confirm inside figures
   are reversed out, and that a very short bar puts its figure outside.
6. Set `corner_radius`, `bar_gap` and `group_gap` and confirm each does
   what it says.
7. Add a negative value. Confirm the bar hangs the other way from zero
   and is rounded on its own far end.
8. Add a `horizontal` marker at a value. On a column chart it should
   run across; switch to `bar` and the same marker should run down,
   still at that value.
9. Add a callout and a note and confirm neither lands on a bar.

## Testing Stage 5

The acceptance test for this stage is the reference design.

1. Open the collagen chart's preview. Every annotation in the file
   should now be drawn.
2. Confirm the dashed **Menopause** marker runs the height of the plot
   at Year 0, with its heading above, and that the series line crosses
   *over* the marker rather than under it.
3. Confirm three emphasised points: hollow at Year 0, filled at 5,
   hollow at 20 with **about 52% remaining** to its left.
4. Confirm **-30%** with **in the first five years** beneath it, and a
   bracket standing off the fall from Year 0 to Year 5 with a stem to
   the figure.
5. Confirm **-2% a year** with **for the next fifteen**, and no leader,
   which is what the file asks for.
6. Confirm the note appears, wrapped onto two lines, clear of the
   Menopause heading. It asked to sit where the heading is, and moved.
7. Delete the marker from a copy of the file and re-import. The note
   should now sit where it originally asked to.
8. Narrow the browser. Nothing should collide at any width.
9. View source and confirm no colour, stroke width or font size in the
   markup. The only `fill=` is the gradient reference.

## Testing Stage 4

The first stage with something to look at.

1. Open the collagen chart from All Charts. A **Preview** box sits at
   the top of the edit screen with the chart drawn in it.
2. Confirm the line runs from top left down to bottom right, with the
   part before Year 0 dotted and faint and the part after solid and
   dark. That is one series in two segments.
3. Confirm the area beneath the line is filled with a gradient that
   fades downward, and that it is one continuous shape with no seam at
   Year 0.
4. Confirm one solid gridline at 100% and two dotted ones at 75 and 50,
   which is exactly what the file asks for.
5. Confirm Year 0, 5 and 20 are dark and 10 and 15 are faint.
6. Confirm both axis titles appear, the y one running bottom to top,
   and that neither overlaps its tick labels.
7. Narrow the browser to phone width. The labels should grow relative
   to the chart rather than shrinking away, and nothing should collide.
8. Turn JavaScript off and reload. The chart must be unchanged.
9. View source and search for `fill=`, `stroke=` and `font-size=`. The
   only match should be the `fill="url(#...)"` on the area path.
10. Confirm the annotations are not drawn yet. The marker, callouts and
    notes arrive at Stage 5, and the preview says so beneath the chart.
11. Put two charts on one page and confirm neither steals the other's
    gradient.

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
   nothing was ignored, dropped or repaired, with 8 data points and 7
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
