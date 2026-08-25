# Style controls

Every control the style engine offers, generated from
`KDNA_Charts_Style_Schema` so it cannot drift from the code.

There are **149** of them. Each writes exactly one CSS custom
property, and every custom property in `assets/css/kdna-charts.css` has
exactly one control. A test asserts both directions.

These keys are what the global styling page saves, what a chart's
`style` object carries, and what a preset file holds. The three are the
same vocabulary in the same shapes, which is why a preset exported from
one chart can be imported as the site's defaults.

## Value shapes

| Type | Shape |
| --- | --- |
| `colour` | `"#14332c"`, `"rgba(0,0,0,.5)"`, `"transparent"` |
| `slider` | `{ "size": 18, "unit": "px" }` |
| `dimensions` | `{ "top": 1, "right": 1, "bottom": 1, "left": 1, "unit": "rem" }` |
| `select` | one of the listed values |
| `number` | a number |

## Responsive controls

A control marked responsive stores the shape above again under a
breakpoint key:

```json
{ "desktop": { "size": 18, "unit": "px" },
  "tablet":  { "size": 24, "unit": "px" },
  "mobile":  { "size": 30, "unit": "px" } }
```

A breakpoint left out inherits the one above it: mobile falls back to
tablet, and tablet to desktop. A bare value written without a
breakpoint key is read as desktop.

Leaving a control unset entirely is not the same as setting it to its
default. An unset control emits no property at all, so the stylesheet's
own value applies, including the rules that grow labels and thicken
lines as a chart narrows. Setting a value replaces that behaviour with
the value, at every width, which is why every dimensional control offers
three breakpoints.

## Breakpoints

| Key | Applies |
| --- | --- |
| `desktop` | everywhere, unless a narrower one overrides it |
| `tablet` | 1024px and below |
| `mobile` | 767px and below |

## Chart Frame

10 controls.

| Key | Control | Type | Responsive | Property | Default |
| --- | --- | --- | --- | --- | --- |
| `frame_background` | Background | colour | no | `--kdna-chart-background` | `transparent` |
| `frame_max_width` | Maximum Width | slider (%, px, rem) | yes | `--kdna-chart-max-width` | `100%` |
| `frame_align` | Alignment | select: `left`, `center`, `right` | yes | `--kdna-chart-margin-inline` | `left` |
| `frame_margin_block` | Space Above and Below | slider (rem, px, em) | yes | `--kdna-chart-margin-block` | `2rem` |
| `frame_padding` | Padding | dimensions (px, rem, em, %) | yes | `--kdna-chart-padding` | `0` |
| `frame_radius` | Corner Radius | dimensions (px, rem, %) | yes | `--kdna-chart-radius` | `0` |

### Border

| Key | Control | Type | Responsive | Property | Default |
| --- | --- | --- | --- | --- | --- |
| `frame_border_style` | Border Style | select: `none`, `solid`, `dashed`, `dotted`, `double` | no | `--kdna-chart-border-style` | `solid` |
| `frame_border_width` | Border Width | dimensions (px, em) | yes | `--kdna-chart-border-width` | `0` |
| `frame_border_colour` | Border Colour | colour | no | `--kdna-chart-border-colour` | `transparent` |

| Key | Control | Type | Responsive | Property | Default |
| --- | --- | --- | --- | --- | --- |
| `frame_font_family` | Font Family | text | no | `--kdna-chart-font-family` | `inherit` |

## Caption

10 controls.

| Key | Control | Type | Responsive | Property | Default |
| --- | --- | --- | --- | --- | --- |
| `caption_size` | Size | slider (rem, px, em) | yes | `--kdna-chart-caption-size` | `1rem` |
| `caption_weight` | Weight | select: `300`, `400`, `500`, `600`, `700`, `800`, `900` | no | `--kdna-chart-caption-weight` | `400` |
| `caption_colour` | Colour | colour | no | `--kdna-chart-caption-colour` | `#4a5f58` |
| `caption_align` | Alignment | select: `left`, `center`, `right` | yes | `--kdna-chart-caption-align` | `left` |
| `caption_margin` | Margin | dimensions (rem, px, em) | yes | `--kdna-chart-caption-margin` | `0.75rem 0 0` |
| `caption_line_height` | Line Height | slider (none, px, em, rem) | yes | `--kdna-chart-caption-line-height` | `1.5` |
| `caption_letter_spacing` | Letter Spacing | slider (em, px, rem) | yes | `--kdna-chart-caption-letter-spacing` | `normal` |
| `caption_transform` | Transform | select: `none`, `uppercase`, `lowercase`, `capitalize` | no | `--kdna-chart-caption-transform` | `none` |
| `caption_style` | Style | select: `normal`, `italic` | no | `--kdna-chart-caption-style` | `normal` |
| `caption_font_family` | Font Family | text | no | `--kdna-chart-caption-family` | `the chart font` |

## Plot Area

4 controls.

| Key | Control | Type | Responsive | Property | Default |
| --- | --- | --- | --- | --- | --- |
| `plot_background` | Background | colour | no | `--kdna-chart-plot-background` | `transparent` |
| `plot_border_colour` | Border Colour | colour | no | `--kdna-chart-plot-border-colour` | `transparent` |
| `plot_border_width` | Border Width | slider (px) | yes | `--kdna-chart-plot-border-width` | `0` |
| `plot_radius` | Corner Radius | slider (px) | yes | `--kdna-chart-plot-radius` | `0` |

## Gridlines

7 controls.

| Key | Control | Type | Responsive | Property | Default |
| --- | --- | --- | --- | --- | --- |
| `gridline_colour` | Colour | colour | no | `--kdna-chart-gridline-colour` | `#d5ddd9` |
| `gridline_colour_strong` | Colour, Emphasised | colour | no | `--kdna-chart-gridline-colour-strong` | `#9aa8a3` |
| `gridline_colour_muted` | Colour, Muted | colour | no | `--kdna-chart-gridline-colour-muted` | `#e4e9e7` |
| `gridline_width` | Width | slider (px) | yes | `--kdna-chart-gridline-width` | `1.5px` |
| `gridline_width_strong` | Width, Emphasised | slider (px) | yes | `--kdna-chart-gridline-width-strong` | `2px` |

### Dash Patterns

| Key | Control | Type | Responsive | Property | Default |
| --- | --- | --- | --- | --- | --- |
| `gridline_dash_dashed` | Dashed Pattern | text | no | `--kdna-chart-gridline-dash-dashed` | `10px 8px` |
| `gridline_dash_dotted` | Dotted Pattern | text | no | `--kdna-chart-gridline-dash-dotted` | `2px 8px` |

## Axis Labels

12 controls.

### Tick Labels

| Key | Control | Type | Responsive | Property | Default |
| --- | --- | --- | --- | --- | --- |
| `axis_label_size` | Size | slider (px) | yes | `--kdna-chart-axis-label-size` | `18px` |
| `axis_label_weight` | Weight | select: `300`, `400`, `500`, `600`, `700`, `800`, `900` | no | `--kdna-chart-axis-label-weight` | `400` |
| `axis_label_weight_strong` | Weight, Emphasised | select: `300`, `400`, `500`, `600`, `700`, `800`, `900` | no | `--kdna-chart-axis-label-weight-strong` | `600` |
| `axis_label_colour` | Colour | colour | no | `--kdna-chart-axis-label-colour` | `#4a5f58` |
| `axis_label_colour_strong` | Colour, Emphasised | colour | no | `--kdna-chart-axis-label-colour-strong` | `#14332c` |
| `axis_label_colour_muted` | Colour, Muted | colour | no | `--kdna-chart-axis-label-colour-muted` | `#a8b3af` |
| `axis_label_letter_spacing` | Letter Spacing | slider (em, px, rem) | yes | `--kdna-chart-axis-label-letter-spacing` | `0` |

### Axis Titles

| Key | Control | Type | Responsive | Property | Default |
| --- | --- | --- | --- | --- | --- |
| `axis_title_size` | Size | slider (px) | yes | `--kdna-chart-axis-title-size` | `18px` |
| `axis_title_weight` | Weight | select: `300`, `400`, `500`, `600`, `700`, `800`, `900` | no | `--kdna-chart-axis-title-weight` | `600` |
| `axis_title_colour` | Colour | colour | no | `--kdna-chart-axis-title-colour` | `#6b7a75` |
| `axis_title_letter_spacing` | Letter Spacing | slider (em, px, rem) | yes | `--kdna-chart-axis-title-letter-spacing` | `0.06em` |
| `axis_title_transform` | Transform | select: `none`, `uppercase`, `lowercase`, `capitalize` | no | `--kdna-chart-axis-title-transform` | `uppercase` |

## Series

44 controls.

### Lines

| Key | Control | Type | Responsive | Property | Default |
| --- | --- | --- | --- | --- | --- |
| `series_colour_strong` | Line Colour, Emphasised | colour | no | `--kdna-chart-series-strong` | `#14332c` |
| `series_colour_normal` | Line Colour | colour | no | `--kdna-chart-series-normal` | `#4a5f58` |
| `series_colour_muted` | Line Colour, Muted | colour | no | `--kdna-chart-series-muted` | `#9aa8a3` |
| `line_width_strong` | Line Width, Emphasised | slider (px) | yes | `--kdna-chart-line-width-strong` | `3.5px` |
| `line_width_normal` | Line Width | slider (px) | yes | `--kdna-chart-line-width-normal` | `2.5px` |
| `line_width_muted` | Line Width, Muted | slider (px) | yes | `--kdna-chart-line-width-muted` | `2px` |
| `line_dash_dashed` | Dashed Pattern | text | no | `--kdna-chart-dash-dashed` | `14px 10px` |
| `line_dash_dotted` | Dotted Pattern | text | no | `--kdna-chart-dash-dotted` | `0.1px 9px` |

### Series Palette

| Key | Control | Type | Responsive | Property | Default |
| --- | --- | --- | --- | --- | --- |
| `series_colour_1` | Series 1 | colour | no | `--kdna-chart-series-colour-1` | `#14332c` |
| `series_colour_2` | Series 2 | colour | no | `--kdna-chart-series-colour-2` | `#3d5f55` |
| `series_colour_3` | Series 3 | colour | no | `--kdna-chart-series-colour-3` | `#6b8b80` |
| `series_colour_4` | Series 4 | colour | no | `--kdna-chart-series-colour-4` | `#9db3ab` |
| `series_colour_5` | Series 5 | colour | no | `--kdna-chart-series-colour-5` | `#c3d2cc` |
| `series_colour_6` | Series 6 | colour | no | `--kdna-chart-series-colour-6` | `#e2e9e6` |

### Area Fills

| Key | Control | Type | Responsive | Property | Default |
| --- | --- | --- | --- | --- | --- |
| `area_colour_strong` | Emphasised: Colour | colour | no | `--kdna-chart-area-colour-strong` | `#14332c` |
| `area_opacity_top_strong` | Emphasised: Opacity at the Line | number | no | `--kdna-chart-area-opacity-top-strong` | `0.16` |
| `area_opacity_bottom_strong` | Emphasised: Opacity at the Axis | number | no | `--kdna-chart-area-opacity-bottom-strong` | `0` |
| `area_colour_normal` | Normal: Colour | colour | no | `--kdna-chart-area-colour-normal` | `#4a5f58` |
| `area_opacity_top_normal` | Normal: Opacity at the Line | number | no | `--kdna-chart-area-opacity-top-normal` | `0.12` |
| `area_opacity_bottom_normal` | Normal: Opacity at the Axis | number | no | `--kdna-chart-area-opacity-bottom-normal` | `0` |
| `area_colour_muted` | Muted: Colour | colour | no | `--kdna-chart-area-colour-muted` | `#9aa8a3` |
| `area_opacity_top_muted` | Muted: Opacity at the Line | number | no | `--kdna-chart-area-opacity-top-muted` | `0.07` |
| `area_opacity_bottom_muted` | Muted: Opacity at the Axis | number | no | `--kdna-chart-area-opacity-bottom-muted` | `0` |

### Bars and Columns

| Key | Control | Type | Responsive | Property | Default |
| --- | --- | --- | --- | --- | --- |
| `bar_colour_strong` | Colour, Emphasised | colour | no | `--kdna-chart-bar-colour-strong` | `#14332c` |
| `bar_colour_normal` | Colour | colour | no | `--kdna-chart-bar-colour-normal` | `#4a5f58` |
| `bar_colour_muted` | Colour, Muted | colour | no | `--kdna-chart-bar-colour-muted` | `#b6c2bd` |
| `bar_opacity` | Opacity | number | no | `--kdna-chart-bar-opacity` | `1` |
| `bar_stroke_colour` | Outline Colour | colour | no | `--kdna-chart-bar-stroke-colour` | `transparent` |
| `bar_stroke_width` | Outline Width | slider (px) | yes | `--kdna-chart-bar-stroke-width` | `0` |

### Value Labels

| Key | Control | Type | Responsive | Property | Default |
| --- | --- | --- | --- | --- | --- |
| `value_label_size` | Size | slider (px) | yes | `--kdna-chart-value-label-size` | `20px` |
| `value_label_weight` | Weight | select: `300`, `400`, `500`, `600`, `700`, `800`, `900` | no | `--kdna-chart-value-label-weight` | `600` |
| `value_label_colour` | Colour | colour | no | `--kdna-chart-value-label-colour` | `#14332c` |
| `value_label_colour_inside` | Colour, Inside the Bar | colour | no | `--kdna-chart-value-label-colour-inside` | `#fff` |

### Pie and Donut

| Key | Control | Type | Responsive | Property | Default |
| --- | --- | --- | --- | --- | --- |
| `segment_stroke_colour` | Segment Gap Colour | colour | no | `--kdna-chart-segment-stroke-colour` | `transparent` |
| `segment_stroke_width` | Segment Gap Width | slider (px) | yes | `--kdna-chart-segment-stroke-width` | `0` |
| `segment_label_size` | Label Size | slider (px) | yes | `--kdna-chart-segment-label-size` | `20px` |
| `segment_label_weight` | Label Weight | select: `300`, `400`, `500`, `600`, `700`, `800`, `900` | no | `--kdna-chart-segment-label-weight` | `600` |
| `segment_label_colour` | Label Colour | colour | no | `--kdna-chart-segment-label-colour` | `#14332c` |
| `segment_label_colour_inside` | Label Colour, Inside the Segment | colour | no | `--kdna-chart-segment-label-colour-inside` | `#fff` |
| `segment_centre_size` | Donut Centre Size | slider (px) | yes | `--kdna-chart-segment-centre-size` | `64px` |
| `segment_centre_weight` | Donut Centre Weight | select: `300`, `400`, `500`, `600`, `700`, `800`, `900` | no | `--kdna-chart-segment-centre-weight` | `700` |
| `segment_centre_colour` | Donut Centre Colour | colour | no | `--kdna-chart-segment-centre-colour` | `#14332c` |
| `segment_leader_colour` | Leader Colour | colour | no | `--kdna-chart-segment-leader-colour` | `#9aa8a3` |
| `segment_leader_width` | Leader Width | slider (px) | yes | `--kdna-chart-segment-leader-width` | `1.5px` |

## Data Points

7 controls.

| Key | Control | Type | Responsive | Property | Default |
| --- | --- | --- | --- | --- | --- |
| `point_radius` | Radius | slider (px) | yes | `--kdna-chart-point-radius` | `9px` |
| `point_colour` | Colour | colour | no | `--kdna-chart-point-colour` | `#14332c` |
| `point_fill_hollow` | Hollow Fill | colour | no | `--kdna-chart-point-fill-hollow` | `#fff` |
| `point_stroke_width` | Ring Width | slider (px) | yes | `--kdna-chart-point-stroke-width` | `3.5px` |

### Point Labels

| Key | Control | Type | Responsive | Property | Default |
| --- | --- | --- | --- | --- | --- |
| `point_label_size` | Size | slider (px) | yes | `--kdna-chart-point-label-size` | `20px` |
| `point_label_weight` | Weight | select: `300`, `400`, `500`, `600`, `700`, `800`, `900` | no | `--kdna-chart-point-label-weight` | `500` |
| `point_label_colour` | Colour | colour | no | `--kdna-chart-point-label-colour` | `#4a5f58` |

## Markers

9 controls.

### Marker Lines

| Key | Control | Type | Responsive | Property | Default |
| --- | --- | --- | --- | --- | --- |
| `marker_colour` | Colour | colour | no | `--kdna-chart-marker-colour` | `#6b7a75` |
| `marker_width` | Width | slider (px) | yes | `--kdna-chart-marker-width` | `2px` |
| `marker_dash_dashed` | Dashed Pattern | text | no | `--kdna-chart-marker-dash-dashed` | `10px 8px` |
| `marker_dash_dotted` | Dotted Pattern | text | no | `--kdna-chart-marker-dash-dotted` | `2px 8px` |

### Marker Headings

| Key | Control | Type | Responsive | Property | Default |
| --- | --- | --- | --- | --- | --- |
| `marker_label_size` | Size | slider (px) | yes | `--kdna-chart-marker-label-size` | `20px` |
| `marker_label_weight` | Weight | select: `300`, `400`, `500`, `600`, `700`, `800`, `900` | no | `--kdna-chart-marker-label-weight` | `600` |
| `marker_label_colour` | Colour | colour | no | `--kdna-chart-marker-label-colour` | `#14332c` |
| `marker_label_letter_spacing` | Letter Spacing | slider (em, px, rem) | yes | `--kdna-chart-marker-label-letter-spacing` | `0.04em` |
| `marker_label_transform` | Transform | select: `none`, `uppercase`, `lowercase`, `capitalize` | no | `--kdna-chart-marker-label-transform` | `none` |

## Callouts

13 controls.

### Figure

| Key | Control | Type | Responsive | Property | Default |
| --- | --- | --- | --- | --- | --- |
| `callout_value_size_large` | Size, Large | slider (px) | yes | `--kdna-chart-callout-value-size-large` | `56px` |
| `callout_value_size_small` | Size, Small | slider (px) | yes | `--kdna-chart-callout-value-size-small` | `34px` |
| `callout_value_weight` | Weight | select: `300`, `400`, `500`, `600`, `700`, `800`, `900` | no | `--kdna-chart-callout-value-weight` | `700` |
| `callout_value_colour` | Colour | colour | no | `--kdna-chart-callout-value-colour` | `#14332c` |
| `callout_value_letter_spacing` | Letter Spacing | slider (em, px, rem) | yes | `--kdna-chart-callout-value-letter-spacing` | `-0.02em` |

### Caption

| Key | Control | Type | Responsive | Property | Default |
| --- | --- | --- | --- | --- | --- |
| `callout_caption_size` | Size | slider (px) | yes | `--kdna-chart-callout-caption-size` | `20px` |
| `callout_caption_weight` | Weight | select: `300`, `400`, `500`, `600`, `700`, `800`, `900` | no | `--kdna-chart-callout-caption-weight` | `400` |
| `callout_caption_colour` | Colour | colour | no | `--kdna-chart-callout-caption-colour` | `#6b7a75` |

### Leaders and Brackets

| Key | Control | Type | Responsive | Property | Default |
| --- | --- | --- | --- | --- | --- |
| `leader_colour` | Leader Colour | colour | no | `--kdna-chart-leader-colour` | `#9aa8a3` |
| `leader_width` | Leader Width | slider (px) | yes | `--kdna-chart-leader-width` | `1.75px` |
| `leader_dash` | Leader Dash Pattern | text | no | `--kdna-chart-leader-dash` | `none` |
| `bracket_colour` | Bracket Colour | colour | no | `--kdna-chart-bracket-colour` | `#9aa8a3` |
| `bracket_width` | Bracket Width | slider (px) | yes | `--kdna-chart-bracket-width` | `1.75px` |

## Notes

4 controls.

| Key | Control | Type | Responsive | Property | Default |
| --- | --- | --- | --- | --- | --- |
| `note_size` | Size | slider (px) | yes | `--kdna-chart-note-size` | `20px` |
| `note_weight` | Weight | select: `300`, `400`, `500`, `600`, `700`, `800`, `900` | no | `--kdna-chart-note-weight` | `400` |
| `note_colour` | Colour | colour | no | `--kdna-chart-note-colour` | `#8a968f` |
| `note_style` | Style | select: `normal`, `italic` | no | `--kdna-chart-note-style` | `italic` |

## Legend

3 controls.

| Key | Control | Type | Responsive | Property | Default |
| --- | --- | --- | --- | --- | --- |
| `legend_label_size` | Label Size | slider (px) | yes | `--kdna-chart-legend-label-size` | `20px` |
| `legend_label_weight` | Label Weight | select: `300`, `400`, `500`, `600`, `700`, `800`, `900` | no | `--kdna-chart-legend-label-weight` | `400` |
| `legend_label_colour` | Label Colour | colour | no | `--kdna-chart-legend-label-colour` | `#4a5f58` |

## Source Line

10 controls.

| Key | Control | Type | Responsive | Property | Default |
| --- | --- | --- | --- | --- | --- |
| `source_size` | Size | slider (rem, px, em) | yes | `--kdna-chart-source-size` | `0.8125rem` |
| `source_weight` | Weight | select: `300`, `400`, `500`, `600`, `700`, `800`, `900` | no | `--kdna-chart-source-weight` | `400` |
| `source_colour` | Colour | colour | no | `--kdna-chart-source-colour` | `#8a968f` |
| `source_align` | Alignment | select: `left`, `center`, `right` | yes | `--kdna-chart-source-align` | `left` |
| `source_margin` | Margin | dimensions (rem, px, em) | yes | `--kdna-chart-source-margin` | `0.5rem 0 0` |
| `source_line_height` | Line Height | slider (none, px, em, rem) | yes | `--kdna-chart-source-line-height` | `1.5` |
| `source_letter_spacing` | Letter Spacing | slider (em, px, rem) | yes | `--kdna-chart-source-letter-spacing` | `normal` |
| `source_transform` | Transform | select: `none`, `uppercase`, `lowercase`, `capitalize` | no | `--kdna-chart-source-transform` | `none` |
| `source_style` | Style | select: `normal`, `italic` | no | `--kdna-chart-source-style` | `normal` |
| `source_font_family` | Font Family | text | no | `--kdna-chart-source-family` | `the chart font` |

## Stat Blocks

16 controls.

| Key | Control | Type | Responsive | Property | Default |
| --- | --- | --- | --- | --- | --- |
| `stat_gap` | Gap Between Blocks | slider (rem, px, em) | yes | `--kdna-chart-stat-gap` | `2rem` |
| `stat_padding` | Padding Inside a Block | dimensions (rem, px, em) | yes | `--kdna-chart-stat-padding` | `0` |

### Figure

| Key | Control | Type | Responsive | Property | Default |
| --- | --- | --- | --- | --- | --- |
| `stat_number_size` | Size | slider (rem, px, em) | yes | `--kdna-chart-stat-number-size` | `clamp( 2.4rem, 6vw, 3.6rem )` |
| `stat_number_weight` | Weight | select: `300`, `400`, `500`, `600`, `700`, `800`, `900` | no | `--kdna-chart-stat-number-weight` | `700` |
| `stat_number_colour` | Colour | colour | no | `--kdna-chart-stat-number-colour` | `#14332c` |
| `stat_number_leading` | Line Height | slider (none, px, em, rem) | yes | `--kdna-chart-stat-number-leading` | `1` |
| `stat_number_tracking` | Letter Spacing | slider (em, px, rem) | yes | `--kdna-chart-stat-number-tracking` | `-0.03em` |

### Suffix

| Key | Control | Type | Responsive | Property | Default |
| --- | --- | --- | --- | --- | --- |
| `stat_suffix_size` | Size | slider (em, rem, px) | yes | `--kdna-chart-stat-suffix-size` | `0.5em` |
| `stat_suffix_weight` | Weight | select: `300`, `400`, `500`, `600`, `700`, `800`, `900` | no | `--kdna-chart-stat-suffix-weight` | `600` |
| `stat_suffix_colour` | Colour | colour | no | `--kdna-chart-stat-suffix-colour` | `#6b7a75` |

### Label

| Key | Control | Type | Responsive | Property | Default |
| --- | --- | --- | --- | --- | --- |
| `stat_label_size` | Size | slider (rem, px, em) | yes | `--kdna-chart-stat-label-size` | `0.9375rem` |
| `stat_label_weight` | Weight | select: `300`, `400`, `500`, `600`, `700`, `800`, `900` | no | `--kdna-chart-stat-label-weight` | `500` |
| `stat_label_colour` | Colour | colour | no | `--kdna-chart-stat-label-colour` | `#6b7a75` |
| `stat_label_tracking` | Letter Spacing | slider (em, px, rem) | yes | `--kdna-chart-stat-label-tracking` | `0.02em` |

### Divider

| Key | Control | Type | Responsive | Property | Default |
| --- | --- | --- | --- | --- | --- |
| `stat_rule_width` | Width | slider (px, em) | yes | `--kdna-chart-stat-rule-width` | `0` |
| `stat_rule_colour` | Colour | colour | no | `--kdna-chart-stat-rule-colour` | `#d5ddd9` |
