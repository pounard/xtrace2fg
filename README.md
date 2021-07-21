# XDebug TRACE files to FlameGraph converter

From a given XDebug generated trace file, outputs text suitable for
https://github.com/brendangregg/FlameGraph flamegraph generator.

This requires `xdebug.trace_format` setting to be set to `1`.

## Prerequisites

 - You need https://github.com/brendangregg/FlameGraph to be installed somewhere.
 - `xdebug.trace_format` setting must be `1` in PHP configuration.

## Usage

This will output a trace file using time relative cost for each function:

```sh
xtrace2fg TRACE_FILE | flamegraph.pl > OUTPUT.svg
```

And this will output a trace file using memory relative cost for each function:

```sh
xtrace2fg memory TRACE_FILE | flamegraph.pl > OUTPUT.svg
```

Where:

 * FILE is the XDebug TRACE file (generated using xdebug.trace_format=1)

 * OUTPUT.svg if the output filename

You can load the SVG output file into any recent browser to benefit from
browsing capabilities in the flame graph.

Warning, memory support is experimental, and is NOT doing what you'd expect
because it will revert all functions that actually free memory to 0. You
will see functions that consume memory without freeing it, but it not
necessarily mean it's a leak, since it could be garbage collected somewhere
else.

## Generated sample trace file

```sh
XDEBUG_MODE=trace XDEBUG_TRIGGER=1 php -d'xdebug.trace_format=1' -f samples/test.php
cp /tmp/trace.XXXXXX.xt samples/test.xt

./xtrace2fg samples/test.xt > samples/test.cost.output
cat samples/test.cost.output | ~/FlameGraph/flamegraph.pl > samples/test.cost.svg

./xtrace2fg memory samples/test.xt > samples/test.memory.output
cat samples/test.memory.output | ~/FlameGraph/flamegraph.pl --color=mem > samples/test.memory.svg
```
