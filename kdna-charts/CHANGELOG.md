# Changelog

All notable changes to KDNA Charts are recorded here. The plugin follows
semantic versioning: patch releases fix bugs, minor releases add features
without breaking existing charts, major releases may change the schema.

## 1.0.1

Fixes to callout rendering, found on a dense bar chart where the callout
landed on top of the bars it described.

- **Callout placement.** The placer searched only a ring of positions close
  to the anchor, so on a dense chart where the only clear space is further
  off it settled for sitting on a bar. It now searches the whole plot and
  chooses the nearest genuinely empty spot, still preferring a position
  beside the mark where one is free.
- **Callout leader.** When the placer has to move a callout well clear of
  its anchor, a connecting leader is now drawn even where the definition
  asked for none, so the relocated figure does not read as disconnected.
- **Leader clamping.** A leader's target is kept inside the plot, so a
  callout anchored just outside it no longer draws a line off the edge of
  the chart.
- **Caption spacing.** A callout's caption sat a full line height below the
  figure and floated; it now sits closer, at 0.92 of the figure size, and
  reads as one block with the number above it.

No schema change. Existing charts pick up the fixes on the next render,
with no edit to their JSON.

## 1.0.0

First complete release. All thirteen build stages consolidated into one
plugin.

- The `kdna_chart` custom post type, JSON import (file and paste) with a
  discard, drop and repair report, and the chart definition schema.
- The scale engine and the SVG renderer, with the full editorial annotation
  layer: markers, points, callouts with leaders, and notes.
- All seven v1 chart types: line, area, bar, column, pie, donut and stat.
- The chart editor: Data, Annotations, Options and Style tabs, a spreadsheet
  grid, and a server-rendered live preview.
- The Style Engine: a schema that is the single source of truth for every
  control, a resolver that treats absent as inherit, the Settings > KDNA
  Charts page with a live preview and preset export and import, and a
  per-chart overrides panel, every dimensional control responsive across
  three breakpoints.
- The `[kdna_chart]` shortcode, styles resolved onto the figure so it works
  inside a JetEngine repeater, with an always-load stylesheet setting.
- The Elementor widget in the KDNA Tools category, its Style tab generated
  from the schema and resolved through the shared renderer so it styles both
  engines.
- The Chart.js engine, styled from the same resolved values, with tooltips
  and a toggleable legend, its library bundled locally and enqueued only
  where a Chart.js chart renders.
- The front-end layer: scroll-triggered draw-in animation, an optional
  screen-reader data table, and mobile axis-label thinning.
- A neutral grey default palette.
