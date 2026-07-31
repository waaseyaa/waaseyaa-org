---
title: Docs chat retrieval quality pass
horizon: next
status_note: Known mis-ordering cases documented; index works, refinement queued
related_specs: workspace-chat-surface
weight: 1
---

Retrieval ranks specs through a title-weighted FTS5 index. Remaining
refinements: stemmed token comparison so plural queries match, and title
weighting that is robust to long titles repeating a package name.
