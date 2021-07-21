<?php

function usage($programeName) {
    echo <<<EOT
Usage: {$programeName} TRACE_FILE [COST_FACTOR] | flamegraph.pl > OUTPUT.svg

Prerequisites:
  - You need https://github.com/brendangregg/FlameGraph to be installed

Where
  - FILE is the XDebug TRACE file (generated using xdebug.trace_format=1)
  - OUTPUT.svg if the output filename
  - COST_FACTOR is an optional CPU cost factor, default is 10000000
    use this if generated self cost per line don't mean anything

You can load the SVG output file into any recent browser to benefit from
browsing capabilities in the flame graph.

EOT;
}

if (empty($argv[1])) {
    usage($argv[0]);
    die();
}
if (!file_exists($argv[1])) {
    die("input file does not exist");
}
if (!is_readable($argv[1])) {
    die("cannot read input file");
}

const COST_FACTOR = 1000000;

class TraceNode
{
    private string $name;
    private ?string $prefix = null;
    private float $costStart = 0;
    private int $costStop = 0;
    private int $memoryStart = 0;
    private int $memoryStop = 0;

    public function __construct(string $name, float $costStart, int $memoryStart, ?string $prefix)
    {
        $this->name = $name;
        $this->costStart = $costStart;
        $this->memoryStart = $memoryStart;
        $this->prefix = $prefix;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getAbsoluteName(): string
    {
        if ($this->prefix) {
            return $this->prefix . ';' . $this->name;
        }
        return $this->name;
    }

    public function exit(float $costStop, int $memoryStop)
    {
        $this->costStop = $costStop;
        $this->memoryStop = $memoryStop;
    }

    public function getInclusiveCost(): float
    {
        return ($this->costStop - $this->costStart);
    }

    public function getSelfCost(): float
    {
        $total = $this->getInclusiveCost();

        /*
        foreach ($this->children as $child) {
            $total -= $child->getInclusiveCost();
        }
         */

        if ($total < 0) {
            //throw new \Exception("Self cost cannot be under 0");
            return 0;
        }

        return $total;
    }

    public function getStopCost(): float
    {
        return $this->costStop;
    }
}

$handle = fopen($argv[1], 'r');
if (!$handle) {
    die("error while opening input file");
}

/**
 * Exit from a function, display its cost.
 */
function handleExit(/* resource */ $handle, array $data, TraceNode $function): void
{
    $function->exit((float) $data[3] * COST_FACTOR, $data[4]);

    echo $function->getAbsoluteName(), ' ', round($function->getSelfCost(), 0), "\n";
}

/**
 * Create and recurse into function.
 */
function createFunction(/* resource */ $handle, array $data, ?TraceNode $parent = null): TraceNode
{
    return new TraceNode(
        (string)$data[5], // Name
        (float) $data[3] * COST_FACTOR, // CPU start
        (int) $data[4], // Memory start
        $parent ? $parent->getAbsoluteName() : null
    );
}

/**
 * Parse next line.
 */
function parseLine(/* resource */ $handle): ?array
{
    while (!\feof($handle)) {
        $line = \stream_get_line($handle, 1000000, "\n");
        // Sometime indent uses more than one \t hence the \array_filter().
        $data = \array_values(
            \array_filter(
                \explode("\t", $line),
                fn ($line) => $line !== ''
            )
        );
        if (\count($data) < 5) {
            continue;
        }
        return $data;
    }
    return null;
}

/**
 * Handle single line.
 *
 * @return bool
 *   Returns true if "exit" is processed.
 */
function handleLine(/* resource */ $handle, array $data, ?TraceNode $parent = null): bool
{
    if (isset($data[5])) {
        $atLeastOne = false;
        $function = createFunction($handle, $data, $parent);
        // Parse all children until exit.
        while ($data = parseLine($handle)) {
            $atLeastOne = true;
            if (!handleLine($handle, $data, $function)) {
                break;
            }
        }
        if (!$atLeastOne) {
            throw new \Exception("File ended with unclosed function " . $function->getAbsoluteName());
        }
        return true;
    } else if (!$parent) {
        throw new \Exception("Cannot exit without parent.");
    } else {
        handleExit($handle, $data, $parent);
        return false;
    }
}

// Handle top-level calls, in PHP there is always one, which is '{main}'
// nevertheless, better be safe than sorry.
while ($data = parseLine($handle)) {
    handleLine($handle, $data);
}
