# XDebug TRACE files to FlameGraph converter


## Prerequisites

 - You need https://github.com/brendangregg/FlameGraph to be installed somewhere.
 - `xdebug.trace_format` setting must be `1` in PHP configuration.

## Usage

```sh
xtrace2fg TRACE_FILE | flamegraph.pl > OUTPUT.svg
```

Where:

 * FILE is the XDebug TRACE file (generated using xdebug.trace_format=1)

 * OUTPUT.svg if the output filename

You can load the SVG output file into any recent browser to benefit from
browsing capabilities in the flame graph.

## Generated sample trace file

```sh
XDEBUG_MODE=trace XDEBUG_TRIGGER=1 php -d'xdebug.trace_format=1' -f samples/test.php
cp /tmp/trace.XXXXXX.xt samples/test.xt
./xtrace2fg samples/test.xt > samples/test.output
./xtrace2fg samples/test.xt | ~/FlameGraph/flamegraph.pl > samples/test.svg
```
