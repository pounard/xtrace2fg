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

const COST_FACTOR = 10000000;

class TraceNode
{
    private $children = [];
    private $name;
    private $costStart = 0;
    private $costStop = 0;
    private $memoryStart = 0;
    private $memoryStop = 0;
    private $parent;

    public function __construct(string $name, float $costStart, int $memoryStart)
    {
        $this->name = $name;
        $this->costStart = $costStart;
        $this->memoryStart = $memoryStart;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function hasChildren(): bool
    {
        return !empty($this->children);
    }

    public function getChildren(): array
    {
        return $this->children;
    }

    public function addChild(TraceNode $child)
    {
        $this->children[] = $child;
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

        foreach ($this->children as $child) {
            $total -= $child->getInclusiveCost();
        }

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

    public function setParent(TraceNode $parent)
    {
        $this->parent = $parent;

        $parent->addChild($this);
    }

    public function hasParent(): bool
    {
        return null !== $this->parent;
    }

    public function getParent(): TraceNode
    {
        if (!$this->parent) {
            throw new \LogicException(sprintf("node %s has no parent", $this->name));
        }

        return $this->parent;
    }
}

$handle = fopen($argv[1], 'r');
if (!$handle) {
    die("error while opening input file");
}

function recursiveDisplay(TraceNode $node, $level = 0)
{
    $prefix = implode('', array_fill(0, $level * 2, ' '));

    if ($node->hasChildren()) {
        echo $prefix, $node->getName(), ":\n";

        foreach ($node->getChildren() as $child) {
            recursiveDisplay($child, $level + 1);
        }
    } else {
        echo $prefix, $node->getName(), "\n";
    }
}

function recursiveBuildTrace(TraceNode $node, $prefix = '')
{
    if ($prefix) {
        $prefix .= ';' . $node->getName();
    } else {
        $prefix = $node->getName();
    }

    echo $prefix, ' ', round($node->getSelfCost(), 0), "\n";

    if ($node->hasChildren()) {
        foreach ($node->getChildren() as $child) {
            recursiveBuildTrace($child, $prefix);
        }
    }
}

function createFunction(array $data, TraceNode $parent = null)
{
    $function = new TraceNode($data[5], $data[3] * COST_FACTOR, $data[4]);

    if ($parent) {
        $function->setParent($parent);
    }

    return $function;
}

function handleExit(array $data, TraceNode $parent)
{
    $parent->exit($data[3] * COST_FACTOR, $data[4]);

    if ($parent->hasParent()) {
        return $parent->getParent();
    }
}

function handleLine(array $data, TraceNode $parent = null)
{
    $exit = $data[2];

    if (isset($data[5])) {
        return createFunction($data, $parent);
    } else if ($exit) {
        if (!$parent) {
            throw new \Exception(printf("cannot exit without a parent"));
        }
        return handleExit($data, $parent);
    } else {
        throw new \Exception(printf("invalid trace file"));
    }
}

function nextLine($handle)
{
    while (!feof($handle)) {
        $line = stream_get_line($handle, 1000000, "\n");

        // Sometime indent uses more than one \t hence the array_filter
        $data = array_values(
            array_filter(
                explode("\t", $line),
                function ($line) {
                    return $line !== '';
                }
            )
        );

        if (count($data) < 5) {
            continue;
        }

        return $data;
    }
}

$root = null;
$function = null;

while ($data = nextLine($handle)) {

    $function = handleLine($data, $function);

    if (!$root) {
        $root = $function;
    }
}

recursiveBuildTrace($root, '');

