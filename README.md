# XDebug TRACE files to FlameGraph converter


## Prerequisites

You need https://github.com/brendangregg/FlameGraph to be installed.


## Usage

```sh
xtrace2fg TRACE_FILE | flamegraph.pl > OUTPUT.svg
```

Where:

 * FILE is the XDebug TRACE file (generated using xdebug.trace_format=1)

 * OUTPUT.svg if the output filename

You can load the SVG output file into any recent browser to benefit from
browsing capabilities in the flame graph.

