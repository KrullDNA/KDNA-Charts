# KDNA Charts

An editorial charting plugin for WordPress. It exists to produce the kind
of chart that belongs inside an article, a chart that makes an argument,
rather than the kind that belongs on a dashboard.

- **Slug:** `kdna-charts`
- **Version:** 1.0.1
- **Requires:** WordPress 6.0, PHP 8.0
- **Build stage:** 13 of 13 — complete

All thirteen stages are built and consolidated into this one plugin. The
chart post type, the JSON importer, the SVG renderer and its annotation
layer, the style engine (schema, resolver, Settings page and per-chart
panel), the `[kdna_chart]` shortcode, the Elementor widget, the Chart.js
engine, and the front-end enhancement layer all share one spine: one style
vocabulary, one renderer factory, one resolver. The shortcode and the
widget render through the same factory and produce identical markup; the
style engine writes the exact `--kdna-chart-*` custom properties the SVG
stylesheet reads and the Chart.js engine bakes into its config, so the
Settings page controls the real chart in both engines.

## What is built

Every piece below is done. The status table records which stage each
arrived in.

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
| SVG renderer, pie, donut and stat blocks | 7 | Done |
| All seven v1 chart types draw | 7 | Done |
| Chart editor: Data, Annotations, Options and Style tabs | 8 | Done |
| Spreadsheet grid with clipboard paste, segment grouping | 8 | Done |
| Live preview, rendered by the server | 8 | Done |
| Type chooser modal on Add New | 8 | Done |
| Add New screen | 8 | Placeholder, real type chooser at Stage 8 |
| Style engine: schema, resolver, Settings page, per-chart panel, live preview, presets | 9 | Done |
| `[kdna_chart]` shortcode, styles resolved onto the figure, always-load CSS setting | 10 | Done |
| Elementor widget in the KDNA Tools category, Style tab generated from the schema | 11 | Done |
| Chart.js engine, styled from the resolved values, tooltips and toggleable legend | 12 | Done |
| Conditional Chart.js assets, bundled locally, loaded only where a Chart.js chart renders | 12 | Done |
| Front-end layer: scroll-in animation, screen-reader data table, mobile label thinning | 13 | Done |

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
| Style control schema, one control per custom property | 9 | Done |
| Style resolver, absent means inherit, with caching | 9 | Done |
| Settings > KDNA Charts, sectioned controls and a live preview | 9 | Done |
| Per chart overrides, in the editor's Style tab | 9 | Done |
| Preset export and import | 9 | Done |
| Every dimensional control responsive across three breakpoints | 9 | Done |

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
Because the renderer emits geometry and classes only, the style engine
can change how every chart on a site looks by setting custom properties
on a wrapper, with nothing re-rendering and no chart data touched. A
chart that says nothing about its own colours inherits the site palette
automatically, because an unset property falls through to the `--auto`
layer in `assets/css/kdna-charts.css`.

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
Since Stage 9 the style engine also tells the scale what it set, and
the reservation grows to match. See *The style engine* below for why
it reserves for the largest breakpoint rather than the current one.

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

## Pie, donut and stat

**Three shapes of chart, not seven types.** Plotted charts map values
onto axes. Radial charts divide a circle by share. A stat block is
typography. Only the first has a scale, because only the first has
anything to map: a segment's size comes from its share of a total, not
from a position on an axis.

### Arc geometry

Angles run clockwise in degrees, with -90 at twelve o'clock, which is
where a reader starts. That convention lives in one method and nothing
else has to know it.

Two cases are worth naming:

- **A whole turn draws nothing.** An SVG arc whose start and end are
  the same point is empty, so a chart of one segment would come out
  blank. It is drawn as two half turns instead, and on a donut the
  inner circle is swept the other way so the fill rule takes it out of
  the middle rather than filling it.
- **A lone segment's figure goes in the centre.** The middle of a whole
  turn is the far side of the circle, which is nowhere a reader would
  look. On a donut that centre is exactly what the hole was left for.

### Labels

`inside` prints the share on the segment, reversed out, and darkened
again on the pale half of the series ramp. `outside` stands each label
off on a leader that runs out along the segment's own radius and then
turns horizontal, so the text sits level rather than at an angle.
`legend` puts them in a legend instead.

A segment too narrow to hold its figure goes without one. The measure
of "narrow" is the arc at the radius the figure sits on, capped at the
diameter, **not** the chord between the segment's ends. The chord is
the obvious measure and it is wrong at both extremes: a segment of a
whole turn has a chord of nothing because its ends are the same point,
and a three hundred degree segment has almost as little. Both have room
to spare, and both would have silently lost their figure.

A datum carrying its own `suffix` shows that instead of a percentage.
An author who wrote `4.2` with `m` has said what unit the figure is in,
and a chart of millions should say millions rather than thirty one per
cent.

### Why a stat block is not an SVG

Every other type is drawn, and drawing belongs in a viewBox. A stat
block is not drawn, it is **set**: a row of figures with their labels,
which has to wrap on a narrow screen, reflow with the reader's type
size, and be selectable and translatable like the article around it.

Text inside a viewBox does none of that. It scales with the frame
rather than with the reader, and it cannot wrap without being measured
first, which PHP cannot do. So a stat block is real HTML in a real
grid, and the responsive row is a responsive row rather than an
impression of one. It is a description list, because that is what these
are: a term and the figure that goes with it. The figure is shown first
and read second, which is the right way round for both.

## The style engine

Ported from the KDNA Tables Shortcode Style Engine, and diverging from
it in two places for reasons given below.

Three files, one job each:

- `class-kdna-charts-style-schema.php`: every style control, defined
  once. A hundred and forty nine of them across thirteen sections.
- `class-kdna-charts-style-resolver.php`: stored values into CSS custom
  properties, and the cache in front of that.
- `class-kdna-charts-style-admin.php`: Settings > KDNA Charts, the per
  chart panel, the REST routes, and the sanitisers.

### The cascade

```
assets/css/kdna-charts.css, the --auto layer
  -> the global option, kdna_charts_style_defaults
    -> the chart's own overrides, _kdna_chart_style_overrides
      -> an Elementor widget's controls  (Stage 11)
```

Later wins, and the merge happens at the **leaf**, not at the control. A
global that sets a desktop axis label size and a chart override that
sets only the mobile one produce a result carrying both. Replacing whole
controls would silently drop the global's other breakpoints, so a chart
would lose its desktop size the moment somebody set a mobile one.

### Absent means inherit

A value in its inherit state is skipped entirely rather than written as
an empty value. This is the rule the whole engine rests on, and it holds
at every level: the sanitiser refuses to store an empty value, the
resolver skips it, and the stylesheet's fallback chains only fall
through on a property that was never emitted.

That last part is the one with teeth. A responsive control emits up to
three properties, and a breakpoint with no value emits **nothing**:

```css
--_x: var( --x-tablet, var( --x, var( --x--auto ) ) );
```

Writing `--x-tablet: ;` there would not be absent. The chain would stop
at it and resolve to empty, and the rule reading `--_x` would be invalid
at computed-value time, which paints as unset and inherited, not as the
desktop value. One empty string, and a chart loses its axis labels on a
tablet.

### Three names per token

The stylesheet gives every token three names, and they are not
interchangeable:

| Name | Who writes it | What it is |
| --- | --- | --- |
| `--kdna-chart-x--auto` | this plugin's stylesheet | the default, always set |
| `--kdna-chart-x` | the style engine, inline; or a theme | what somebody set |
| `--_kdna-chart-x` | the stylesheet's resolution layer | what the rules read |

**The indirection is not optional.** An inline style attribute beats
every stylesheet rule, media query or not. So if the engine wrote
`--kdna-chart-axis-label-size` directly and the rules read it directly,
then the moment anybody set an axis label size the narrow-chart rules at
the bottom of the stylesheet would stop working, at every width,
silently, with the labels shrinking away to nothing on a phone. A custom
property also cannot refer to itself, so those rules could not recover
the desktop value either.

Splitting the name in three fixes both. The rules read `--_x`, resolved
from a set value if there is one and from `--auto` if there is not, and
the narrow rules move `--auto`, which no inline attribute ever writes.

The resolution layer is generated from the schema and checked against it
by a test. Do not hand-edit an entry in it.

### Where this diverges from KDNA Tables

**No group controls.** Tables groups its typography, border and
background controls, so one control there owns several properties
through a `fields` array, and its resolver, sanitiser and editor all
carry a nesting level for it. Charts does not need that: the stylesheet
already exposes a flat, complete vocabulary of single-purpose
properties, so a group would be a second structure laid over one that is
already right. What groups bought Tables, a readable panel, is bought
here by a `group` key that is a display heading and nothing more. Every
control writes exactly one property, and a test asserts the reverse too:
every property in the stylesheet has exactly one control.

**No schema defaults.** Tables duplicates every stylesheet value into
its schema so the settings page can show it, and carries a comment about
an upgraded site serving a cached string built by the previous version's
defaults. Here the defaults live once, in the `--auto` layer, and every
schema entry defaults to null. An unset control emits nothing and the
stylesheet decides; "reset to plugin defaults" is literally "store
nothing"; and a later change to a default reaches every site, including
the ones that have saved styles. The schema carries a `placeholder`
string for display only, so a stale one is a cosmetic bug rather than a
behavioural one, and a test keeps it honest anyway.

### What the admin does

Settings > KDNA Charts, with a link to it from the KDNA Charts menu
because that is where somebody working on charts already is.

- Thirteen sections, with a dot on any that carries a value. A setting
  left behind in a section nobody is looking at is otherwise a hunt.
- A filter across every section at once, because with a hundred and
  fifty controls the common case is knowing what you want and not which
  section it is in.
- A live preview in an iframe, at 1200, 900 and 390 pixels. It has to be
  an iframe: two of the three breakpoints are viewport media queries,
  and only a document with a real 390px viewport makes the mobile query
  fire. Anything else would mean a second copy of the resolution layer.
- Preset export and import. Export writes what is **stored**, not what
  is on screen, and says so when there are unsaved changes; import
  **replaces** rather than merges, and names every key it dropped.

The per chart panel is the same template in `chart` context: every field
shows what it is inheriting until it is explicitly overridden, Override
seeds the control from the value it was inheriting, and Revert clears it
again. It has no iframe of its own: the chart editor already has a live
preview beside it, and the panel paints its properties into that one.

### The preview resolves twice, in two languages

The preview writes custom properties straight into the iframe, so the
resolver exists twice: once in PHP and once, transliterated, in
`kdna-style-admin.js`. Second implementations drift, and this drift
would be invisible, because the preview would simply show something the front
end does not.

Two things hold it in place. Both are driven by the same schema object,
so anything expressible in a schema entry needs no code in either. And
an executable parity test runs both over the same value sets, including
the awkward ones, and compares the property maps key by key.

### Things learned by looking at it

**A tablet label ran into the axis title.** The scale reserves padding
for a tick label from an assumed 24 units, because PHP cannot measure
text, and that held only while labels were drawn smaller than the slot
kept for them. Setting a tablet size of 30 put the label straight
through the title. The scale is now told the largest size configured at
any breakpoint, and reserves for that. It has to be the largest rather
than the current one, because which breakpoint applies is decided by CSS
in the browser at a width PHP will never know: the geometry is settled
once and has to hold at every width.

**A per cent sign is half again the width of a digit.** With the
reservation and the drawn size now equal, the flat 0.62-per-character
estimate stopped having any margin to hide in, and `100%` was
under-measured by about fifteen per cent. The scale now weighs each
character. The annotation layer deliberately does **not** share that
measure: it was tried, and it made the layout worse. Those figures are
one input to a tuned system alongside the wrap fraction, the nudge step
and the box padding, and measuring a note more accurately let two more
words fit on its first line, which made the box wide enough to reach the
callout beside it and got the note thrown eight steps down into the
middle of the curve. More accurate, and a worse chart.

**An unset colour showed a black swatch.** A native colour input cannot
represent "nothing" and cannot show transparency, and six controls here
default to transparent, so the picker sat there in black beside the
word `transparent`, which is a straightforward lie about what the chart
will draw. An unset swatch is struck through instead.

**A bare value was silently dropped.** Half the value shapes here are
arrays (a slider is `{ size, unit }`), so testing whether a stored
value is an array says nothing about whether it is a device map. A
hand-written preset saying `"axis_label_size": { "size": 18, "unit":
"px" }`, which is the obvious way to write it, imported cleanly and then
did nothing at all. The test is now whether a **device key** is present;
the two shapes cannot collide, because no value shape has a key called
desktop, tablet or mobile.

**The default hint said the wrong thing on a chart.** Showing the
stylesheet's value next to a field that inherits from the global layer
put two answers to one question on every row, and one of them was wrong
whenever the global had been set. It is on the settings page only.

## The editor

Four tabs over one Alpine component holding the whole definition, with
a preview beside them.

**One door in.** The editor's state, an imported file and a definition
written by hand all reach storage through the same two steps: the
importer's validator, then `save_definition()`. Nothing in the editor
writes meta directly. That is worth the small awkwardness of running a
validator over data the editor just produced, because it means one
answer to what a chart may contain and no way for the editor to save
something the importer would have refused.

**The preview is drawn on the server.** The renderer is PHP, and a
second renderer in JavaScript would be two answers to every question of
geometry, drifting apart one fix at a time. The editor posts its state
and gets back the same markup the front end will serve. It costs a
debounced request per edit and it cannot lie about the result.

**A save that did not come from this post is refused.** The editor
seeds itself from the definition printed into the page. If that never
arrives, because a script was blocked or reordered by an optimiser,
Alpine falls back to an empty chart, the screen looks like a brand new
one, and pressing Update would write that emptiness over real data.
The seed carries the post id and the fallback does not, so a mismatch
is an exact statement that this state did not come from this post. The
save is refused and the next page load says so. It is deliberately not
a "did it shrink?" check: deleting rows is something people do on
purpose.

### Things learned by looking at it

**A select cannot show what is not there.** Two separate bugs, both
found by rendering the editor and reading it:

- `x-model` binds a select before an `x-for` inside it has rendered its
  options, so the select falls back to its first option while the data
  underneath still holds the real one. The screen lies, and the moment
  anybody touches that select the lie is committed. The vocabularies
  are fixed and PHP already knows them, so the options are printed
  before Alpine looks.
- An enum with no stored value has the same problem for the same
  reason. Per annotation enums are given the schema's default when the
  editor loads, so the control says what will actually be drawn. Chart
  options are not, because those sit in the style cascade and an unset
  one has to stay unset; their selects carry an explicit "Default (x)"
  choice instead.

**Splitting a segment keeps the point in both halves.** That is what
segments are for, and two that share an endpoint join seamlessly while
two that do not leave a gap. Joining one back drops the duplicate.

**A comma is not always a separator.** A single column of figures with
thousands separators looks exactly like two columns of CSV, and
splitting `1,234` into 1 and 234 turns a paste of real data into
nonsense. A comma split is only accepted when the result is the shape
of a table: every row the same width.

**A pasted cell that is not a number blanks the cell** rather than
leaving what was there. The paste said what should be in it, and
quietly keeping an old figure under a new label is how a chart ends up
lying.

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
│   ├── class-kdna-charts-admin.php
│   ├── class-kdna-charts-style-schema.php
│   ├── class-kdna-charts-style-resolver.php
│   └── class-kdna-charts-style-admin.php
├── templates/
│   ├── admin-editor-data.php
│   ├── admin-editor-annotations.php
│   ├── admin-editor-options.php
│   ├── admin-editor-import.php
│   ├── admin-type-chooser-modal.php
│   ├── admin-style-settings.php
│   ├── admin-style-overrides.php
│   ├── admin-style-controls.php
│   ├── admin-style-preview.php
│   ├── admin-style-tools.php
│   ├── render-caption.php
│   ├── render-source.php
│   └── render-placeholder.php
├── assets/
│   ├── css/
│   │   ├── kdna-admin.css
│   │   ├── kdna-style-admin.css
│   │   └── kdna-charts.css
│   └── js/
│       ├── alpine.min.js
│       ├── kdna-admin.js
│       └── kdna-style-admin.js
├── docs/
│   └── chart-schema.md
└── README.md
```

## Testing Stage 9

1. Go to **Settings > KDNA Charts**. Thirteen sections down the left,
   controls on the right, a preview above. Confirm the KDNA Charts menu
   also carries a **Styles** link to the same page.
2. Set **Chart Frame > Background** to something obvious. The preview
   changes as you type, and the save bar says *Unsaved changes*. The
   live chart on the front end does not change until you save.
3. Press **Save Styles**, then reload the front end. It changes.
4. Set **Axis Labels > Tick Labels > Size** to 18 on desktop, then click
   the tablet icon and set 30, then mobile and set 44. A dot appears on
   each breakpoint that carries a value. Switch the preview between
   Desktop, Tablet and Mobile: the labels change at each, and nothing
   collides with the axis title at any of them.
5. Clear the tablet value only. The tablet preview now shows the desktop
   size, because a breakpoint with no value falls through rather than
   resolving to nothing.
6. Type `label size` in the filter. Every section reports how many
   matches it holds, so you can find a control without knowing which
   section it lives in.
7. Press **Export preset**, then **Import preset** and paste the file
   back. It reports how many values it took. Now edit the file to add
   `"nonsense": "#fff"` and import again: it says that key was not
   imported rather than silently dropping it.
8. Open a chart and go to its **Style** tab. Every field reads
   *Inheriting* with the value it is inheriting. Press **Override** on
   one: the control appears, already holding the value it was
   inheriting. Change it, save, and confirm the chart differs from the
   global default while every other chart does not.
9. Press **Revert to global** on that field. It goes back to inheriting.
10. With the Style tab open, change something on the **Data** tab. The
    preview re-renders and keeps the unsaved style edits.
11. As a non-administrator, open a chart. The Style tab explains that
    styling is an administrator setting rather than showing controls
    that would not save.

## Testing Stage 8

1. Click **Add New**. A type chooser appears. Pick one and confirm a
   chart is created and opens in the editor.
2. Open the collagen chart. Confirm the Data tab shows two segments,
   the first dotted and muted, the second solid and strong. If those
   selects say anything else, something is wrong.
3. Confirm the preview on the right shows the chart, and that editing a
   value redraws it within a moment.
4. Copy two columns out of a spreadsheet and paste into any cell of the
   point grid. It should fill from there, adding rows as it needs them.
5. On a bar or column chart, paste a block into the Label column. It
   should bring the names and the figures in together, adding series
   for extra columns.
6. Paste a column of figures written as `1,234` and `56%`. They should
   come in as numbers, not be split on their own separators.
7. Press **Split here** on an interior point. The segment becomes two,
   and the point you split on appears at the end of the first and the
   start of the second. Change one half to dotted and confirm the line
   changes character there.
8. Press **Join to the one above** and confirm the duplicate point is
   not left behind.
9. On the Annotations tab, add a callout, press **Make it a range**,
   and confirm a bracket appears in the preview.
10. On the Options tab, confirm only the options this chart type
    understands appear, and that each shows its stored value or
    **Default**.
11. Save, reload, and confirm everything you entered came back.
12. Block `kdna-admin.js` in the browser's dev tools and reload. The
    editor should refuse to appear and say so, and pressing Update
    should leave the chart untouched with a notice explaining why.

## Testing Stage 7

1. Import a pie with three or four slices. Confirm it starts at twelve
   o'clock and the slices are in the order the file gives them.
2. Change the type to `donut` and confirm a hole appears. Change
   `inner_radius` and confirm the hole follows.
3. Set `labels` to `inside`, then `outside`, then `legend`, then
   `none`. Confirm each does what it says, and that outside labels get
   leader lines that turn horizontal before the text.
4. Set `segment_gap` and confirm the slices separate.
5. Set `start_angle` to 0 and confirm the first slice starts at three
   o'clock.
6. Make a chart of one slice at 100 per cent. It must draw a full ring
   rather than nothing, with its figure in the centre.
7. Add a value of 0 and one that is negative. Both should be left out,
   and the rest should still add up to a full circle.
8. Set `legend` to top, bottom, left and right in turn. A long legend
   along the bottom should wrap onto more rows.
9. Build a stat chart with three or four figures and a suffix on one.
   Confirm the figures are large, the suffix is smaller and in
   proportion, and the labels sit beneath.
10. Narrow the browser. The stat row should wrap; select the text and
    confirm it is real text rather than a picture of it.
11. Set `columns` and `align` on the stat chart and confirm both work.

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
