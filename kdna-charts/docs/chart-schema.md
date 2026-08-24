# KDNA Charts, chart definition schema, version 1

A chart definition is a single JSON object. This document is the prose
mirror of `includes/class-kdna-charts-schema.php`, which is the source of
truth the importer validates against.

Paste this reference into a Claude conversation along with the article
text and the authoring prompt, and Claude returns a file the importer
accepts.

---

## Top level keys

| Key | Type | Notes |
| --- | --- | --- |
| `kdna_chart` | int | Schema version. **Required.** `1` for v1.0.0. |
| `title` | string | Post title in the library. Required, generated if missing. |
| `type` | string | `line`, `area`, `bar`, `column`, `pie`, `donut`, `stat`. **Required.** |
| `engine` | string | `svg` or `chartjs`. Optional, falls back to the global default. |
| `options` | object | Type specific rendering options. |
| `axes` | object | `x` and `y` definitions. Unused by pie, donut and stat. |
| `series` | array | The data. |
| `markers` | array | Vertical or horizontal event lines. |
| `points` | array | Emphasised individual data points. |
| `callouts` | array | Large number annotations with optional leader lines. |
| `notes` | array | Small free floating labels. |
| `source` | string | Attribution line rendered beneath the chart. |
| `caption` | string | Optional caption above or below the chart. |
| `style` | object | Per chart overrides. Any key omitted inherits global. |

Only two things stop an import: no `kdna_chart` key, and no usable
`type`. Everything else is repaired, dropped or discarded, and reported.

---

## Shared vocabularies

The same words mean the same thing everywhere they appear.

| Vocabulary | Values |
| --- | --- |
| emphasis | `strong`, `normal`, `muted` |
| line style | `solid`, `dashed`, `dotted` |
| gridline rule | `none`, `solid`, `dotted`, `dashed` |
| point style | `filled`, `hollow`, `none` |
| leader | `none`, `straight`, `elbow` |
| callout size | `large`, `small` |
| marker type | `vertical`, `horizontal` |
| curve | `linear`, `smooth`, `step` |
| alignment | `left`, `centre`, `right` |
| label position | `top`, `bottom`, `left`, `right`, `top-left`, `top-right`, `bottom-left`, `bottom-right` |
| legend position | `none`, `top`, `bottom`, `left`, `right` |

`emphasis` values map to CSS custom properties rather than fixed
colours, so the site palette still governs. This is why a definition
should almost never name a colour.

---

## `axes`

An object with an `x` key and a `y` key. Both take the same shape.

| Key | Type | Notes |
| --- | --- | --- |
| `label` | string | Axis title. |
| `min` | number | Lower bound. Omit to infer from the data. |
| `max` | number | Upper bound. Omit to infer from the data. |
| `baseline` | number | Where an area fill or a bar is measured from. Defaults to `min`. |
| `categories` | array | Category names, for bar and column charts. |
| `ticks` | array | Explicit ticks. Omit to generate nice numbers automatically. |

### `axes.x.ticks[]` and `axes.y.ticks[]`

| Key | Type | Notes |
| --- | --- | --- |
| `value` | number | **Required.** Where on the axis the tick sits. |
| `label` | string | What the tick reads. Defaults to the value. |
| `emphasis` | string | `strong`, `normal` or `muted`. |
| `rule` | string | Gridline at this tick: `none`, `solid`, `dotted`, `dashed`. |

`emphasis` on a tick is how a chart darkens the values the argument
depends on and mutes the rest. Use it deliberately: if every tick is
strong, none of them is.

---

## `series`

An array. A chart usually has one series; grouped and stacked bar
charts have several.

| Key | Type | Notes |
| --- | --- | --- |
| `id` | string | Stable identifier, generated when absent. |
| `label` | string | Series name, used in the legend and the screen reader table. |
| `emphasis` | string | Series wide emphasis, overridden per segment. |
| `segments` | array | Plotted line segments. Line and area. |
| `data` | array | Flat label and value pairs. Bar, column, pie, donut, stat. |

A series may carry both. Nothing is thrown away when a chart changes
type: the renderer reads whichever shape its type uses.

### `series[].segments[]`

This is what allows one line to change character partway along, the
dotted projection before year zero becoming a solid emphasised line
after it. A simple chart uses a single segment. Segments sharing an
endpoint join seamlessly.

| Key | Type | Notes |
| --- | --- | --- |
| `style` | string | `solid`, `dashed` or `dotted`. |
| `emphasis` | string | `strong`, `normal` or `muted`. |
| `points` | array | **Required.** An ordered list of `[x, y]` pairs. |

A point is a two element array, `[x, y]`. Both are numbers. A `y` of
`null` is a deliberate gap in the line.

A series given a bare `points` array instead of `segments` is wrapped
into one solid segment on import, and the repair is reported.

### `series[].data[]`

| Key | Type | Notes |
| --- | --- | --- |
| `label` | string | What this value is called. |
| `value` | number | **Required.** The figure itself. |
| `suffix` | string | Trailing unit, for example `%`. Stat blocks mainly. |
| `emphasis` | string | Per bar or per segment emphasis. |

---

## `markers`

An event line across the plot, carrying a heading. A vertical marker
needs an `x`, a horizontal marker needs a `y`.

| Key | Type | Notes |
| --- | --- | --- |
| `type` | string | **Required.** `vertical` or `horizontal`. |
| `x` | number | Where a vertical marker sits. |
| `y` | number | Where a horizontal marker sits. |
| `label` | string | The heading carried by the line. |
| `label_position` | string | `top`, `bottom`, `left` or `right`. |
| `style` | string | `solid`, `dashed` or `dotted`. Defaults to dashed. |

Add a marker for any named turning point the text identifies.

---

## `points`

An emphasised individual data point. This exists separately from the
series data because emphasising a point is a design decision, not a
data one.

| Key | Type | Notes |
| --- | --- | --- |
| `x` | number | **Required.** |
| `y` | number | **Required.** |
| `style` | string | `filled`, `hollow` or `none`. |
| `label` | string | Optional label beside the point. |
| `label_position` | string | Any of the eight label positions. |
| `series` | string | Series `id` this point belongs to, when a chart has several. |

---

## `callouts`

The large number annotation. This is the thing that makes an editorial
chart argue rather than report, so use it for the single most important
number in the text, and at most one secondary callout.

| Key | Type | Notes |
| --- | --- | --- |
| `value` | string | **Required.** The figure, written as it should read, for example `-30%`. |
| `caption` | string | The small line beneath the figure. |
| `size` | string | `large` or `small`. |
| `anchor` | object | **Required.** Either a point or a span. |
| `leader` | string | `none`, `straight` or `elbow`. |

`anchor` takes either a single point:

```json
{ "x": 12, "y": 62 }
```

or a span, which draws a leader that brackets the range the number
describes:

```json
{ "from": { "x": 0, "y": 100 }, "to": { "x": 5, "y": 70 } }
```

---

## `notes`

A small free floating label, placed in data coordinates.

| Key | Type | Notes |
| --- | --- | --- |
| `text` | string | **Required.** What the note says. |
| `at` | object | **Required.** `{ "x": n, "y": n }`. |
| `align` | string | `left`, `centre` or `right`. |
| `width` | number | Wrapping width in SVG units. Omit to run on one line. |

Where the article describes a rate rather than points, derive the
points from the rate and record that derivation in a note.

---

## `options`

Options depend on the chart type. An option a type does not understand
is discarded on import and reported.

### Every type

| Key | Type | Default | Notes |
| --- | --- | --- | --- |
| `aspect_ratio` | string | `16:9` | Frame proportion, written `width:height`. |
| `legend` | string | `none` | `none`, `top`, `bottom`, `left`, `right`. |
| `data_table` | bool | `false` | Output a visually hidden data table for screen readers. |

### `line` and `area`

| Key | Type | Default | Notes |
| --- | --- | --- | --- |
| `area_fill` | bool | `true` for area | Fill the space beneath the line with a gradient. |
| `curve` | string | `linear` | `linear`, `smooth` or `step`. |

### `bar` and `column`

| Key | Type | Default | Notes |
| --- | --- | --- | --- |
| `arrangement` | string | `grouped` | `grouped` or `stacked`. |
| `value_labels` | string | `none` | `none`, `inside` or `above`. |
| `corner_radius` | number | `0` | Bar corner radius in SVG units. |
| `bar_gap` | number | `0.2` | Gap between bars in a group, as a fraction of the slot. |
| `group_gap` | number | `0.3` | Gap between groups, as a fraction of the slot. |

### `pie` and `donut`

| Key | Type | Default | Notes |
| --- | --- | --- | --- |
| `start_angle` | number | `-90` | Where the first segment begins. `-90` is twelve o'clock. |
| `inner_radius` | number | `0.6` for donut | Hole size as a fraction of the radius. |
| `segment_gap` | number | `0` | Gap between segments, in degrees. |
| `labels` | string | `outside` | `none`, `inside`, `outside` or `legend`. |

### `stat`

| Key | Type | Default | Notes |
| --- | --- | --- | --- |
| `columns` | int | `3` | How many figures sit across a row on desktop. |
| `align` | string | `left` | `left`, `centre` or `right`. |

---

## `style`

Per chart overrides, in the same vocabulary as the global styling page.
Any key omitted inherits from global, so a definition that says nothing
about colour picks up the site palette automatically.

**Leave `style` as an empty object unless there is a reason not to.**
That is what makes an imported chart look like it belongs to the site
it landed on.

---

## Validation behaviour

| Situation | What happens |
| --- | --- |
| Unknown key | Discarded and reported. Never fatal. |
| No `kdna_chart` key | Import fails with a clear message. |
| `kdna_chart` other than `1` | Import fails with a clear message. |
| No usable `type` | Import fails with a clear message. |
| No `title` | One is generated, and the repair is reported. |
| Malformed point | That point is dropped, the rest of the chart imports. |
| Value outside its allowed range | Brought back into range, and reported. |
| Value not in its list of allowed words | Falls back to the default, and reported. |
| Number written as text, for example `"52%"` | Read as a number, and reported. |
| Markdown code fences around the JSON | Stripped, and reported. |
| Valid key this chart type cannot draw | Kept, and reported as kept but not drawn. |

The importer returns a plain English summary of what was created, what
was ignored, what was dropped and what was repaired.

---

## Worked example

The collagen decline chart. One line that changes character at the
menopause, a marker on that turning point, three emphasised points, a
large callout bracketing the first five years and a small one for the
years after, and a note recording where the pre menopause slope came
from.

```json
{
  "kdna_chart": 1,
  "title": "Skin collagen after menopause",
  "type": "line",
  "engine": "svg",
  "options": {
    "area_fill": true,
    "curve": "smooth",
    "aspect_ratio": "16:9"
  },
  "axes": {
    "x": {
      "label": "Years after menopause",
      "min": -8,
      "max": 20,
      "ticks": [
        { "value": 0,  "label": "Year 0", "emphasis": "strong" },
        { "value": 5,  "label": "5",      "emphasis": "strong" },
        { "value": 10, "label": "10",     "emphasis": "muted"  },
        { "value": 15, "label": "15",     "emphasis": "muted"  },
        { "value": 20, "label": "20",     "emphasis": "strong" }
      ]
    },
    "y": {
      "label": "Collagen remaining",
      "min": 45,
      "max": 108,
      "baseline": 45,
      "ticks": [
        { "value": 100, "label": "100%", "emphasis": "strong", "rule": "solid"  },
        { "value": 75,  "label": "75",   "emphasis": "muted",  "rule": "dotted" },
        { "value": 50,  "label": "50",   "emphasis": "muted",  "rule": "dotted" }
      ]
    }
  },
  "series": [
    {
      "id": "collagen",
      "label": "Skin collagen",
      "segments": [
        {
          "style": "dotted",
          "emphasis": "muted",
          "points": [[-8, 108], [-4, 104], [0, 100]]
        },
        {
          "style": "solid",
          "emphasis": "strong",
          "points": [[0, 100], [5, 70], [10, 64], [15, 58], [20, 52]]
        }
      ]
    }
  ],
  "markers": [
    { "type": "vertical", "x": 0, "label": "Menopause", "label_position": "top" }
  ],
  "points": [
    { "x": 0,  "y": 100, "style": "hollow" },
    { "x": 5,  "y": 70,  "style": "filled" },
    { "x": 20, "y": 52,  "style": "hollow",
      "label": "about 52% remaining", "label_position": "left" }
  ],
  "callouts": [
    {
      "value": "-30%",
      "caption": "in the first five years",
      "size": "large",
      "anchor": { "from": { "x": 0, "y": 100 }, "to": { "x": 5, "y": 70 } },
      "leader": "elbow"
    },
    {
      "value": "-2% a year",
      "caption": "for the next fifteen",
      "size": "small",
      "anchor": { "x": 12, "y": 62 },
      "leader": "none"
    }
  ],
  "notes": [
    { "text": "slow, steady decline, roughly 1% a year",
      "at": { "x": -6, "y": 111 } }
  ],
  "source": "Brincat et al., Obstetrics and Gynecology, 1987",
  "style": {}
}
```
