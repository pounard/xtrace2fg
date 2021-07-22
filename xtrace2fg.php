<?php

function usage($programeName) {
    echo <<<EOT
Usage:

    {$programeName} [self|inclusive] TRACE_FILE | flamegraph.pl > OUTPUT.svg

    OR:

    {$programeName} memory TRACE_FILE | flamegraph.pl --color=mem > OUTPUT.svg

Prerequisites:
  - You need https://github.com/brendangregg/FlameGraph to be installed

Where
  - "memory" is for generating a memory based output whose values are memory
    allocated and not freed by functions (in bytes),
  - "self" (default) is for generating a cost (time) based output whose timings
    are excludes children time. By experience it's the most interesting one.
  - "inclusive" is for generating a cost (time) based output whose
    timings includes children time.
  - FILE is the XDebug TRACE file (generated using xdebug.trace_format=1).
  - OUTPUT.svg if the output filename.

You can load the SVG output file into any recent browser to benefit from
browsing capabilities in the flame graph.

EOT;
}

const MODE_MEMORY = 1;
const MODE_INCLUSIVE = 2;
const MODE_SELF = 3;

global $mode;
$filename = null;

if (empty($argv[1])) {
    usage($argv[0]);
    die();
}

if ('memory' === $argv[1] || 'mem' === $argv[1]) {
    $mode = MODE_MEMORY;
    if (empty($argv[2])) {
        usage($argv[0]);
        die();
    }
    $filename = $argv[2];
} else if ('self' === $argv[1]) {
    $mode = MODE_SELF;
    if (empty($argv[2])) {
        usage($argv[0]);
        die();
    }
    $filename = $argv[2];
} else if ('inclusive' === $argv[1]) {
    $mode = MODE_INCLUSIVE;
    if (empty($argv[2])) {
        usage($argv[0]);
        die();
    }
    $filename = $argv[2];
} else {
    $mode = MODE_SELF;
    $filename = $argv[1];
}

if (!file_exists($filename)) {
    die("input file does not exist");
}
if (!is_readable($filename)) {
    die("cannot read input file");
}

const COST_FACTOR = 1000000;

class TraceNode
{
    private string $name;
    private ?string $prefix = null;
    private float $costStart = 0.0;
    private float $costStop = 0.0;
    private int $memoryStart = 0;
    private int $memoryStop = 0;
    private float $childCost = 0.0;
    private int $childMemory = 0;

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

    public function addChildCost(TraceNode $node): void
    {
        $this->childCost += $node->getInclusiveCost();
        $this->childMemory += $node->getInclusiveMemory();
    }

    public function getInclusiveCost(): float
    {
        return ($this->costStop - $this->costStart);
    }

    public function getSelfCost(): float
    {
        return $this->getInclusiveCost() - $this->childCost;
    }

    public function getInclusiveMemory(): int
    {
        return ($this->memoryStop - $this->memoryStart);
    }

    public function getSelfMemory(): float
    {
        return $this->getInclusiveMemory() - $this->childMemory;
    }
}

$handle = fopen($filename, 'r');
if (!$handle) {
    die("error while opening input file");
}

/**
 * Exit from a function, display its cost.
 */
function handleExit(/* resource */ $handle, array $data, TraceNode $function): void
{
    global $mode;

    $function->exit((float) $data[3] * COST_FACTOR, $data[4]);

    switch ($mode) {
        case MODE_MEMORY:
            if (0 > ($bytes = $function->getSelfMemory())) {
                echo $function->getAbsoluteName(), " 0\n";
            } else {
                echo $function->getAbsoluteName(), ' ', $bytes, "\n";
            }
            break;

        case MODE_INCLUSIVE:
            echo $function->getAbsoluteName(), ' ', round($function->getInclusiveCost(), 0), "\n";
            break;

        case MODE_SELF:
        default:
            echo $function->getAbsoluteName(), ' ', round($function->getSelfCost(), 0), "\n";
            break;
    }
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
 * @return ?RelativeCost
 *   Sum of relative costs. Null if exit.
 */
function handleLine(/* resource */ $handle, array $data, ?TraceNode $parent = null): ?TraceNode
{
    if (isset($data[5])) {
        $atLeastOne = false;
        $function = createFunction($handle, $data, $parent);
        // Parse all children until exit.
        while ($data = parseLine($handle)) {
            $atLeastOne = true;
            if ($childNode = handleLine($handle, $data, $function)) {
                $function->addChildCost($childNode); 
            } else{
                break; // We just found the exit statement of this function.
            }
        }
        if (!$atLeastOne) {
            throw new \Exception("File ended with unclosed function " . $function->getAbsoluteName());
        }
        return $function;
    } else if (!$parent) {
        throw new \Exception("Cannot exit without parent.");
    } else {
        handleExit($handle, $data, $parent);
        return null;
    }
}

// Handle top-level calls, in PHP there is always one, which is '{main}'
// nevertheless, better be safe than sorry.
while ($data = parseLine($handle)) {
    handleLine($handle, $data);
}
