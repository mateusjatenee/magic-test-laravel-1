<?php

namespace MagicTest\MagicTest\Parser;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class File
{
    const MACRO = '->magic()';

    public $content;
    public $method;

    public $lines;

    public $stopTestsBeforeKey;

    public $initialMethodLine;

    public $currentLineInIteration;

    public $writingTest = false;

    public $lastLineAdded;

    protected $possibleMethods = ['MagicTestManager::run', 'magic_test', 'magic', 'm('];

    public function __construct(string $content, string $method)
    {
        $this->content = $content;
        $this->method = $method;
        $this->lines = $this->generateLines();
        $this->lastLineAdded = $this->getLastAction();
    }

    public static function fromContent(string $content, string $method): self
    {
        return new static($content, $method);
    }

    public function getLastAction(): Line
    {
        $fullMethod = 'public function ' . $this->method;

        $this->initialMethodLine = $this->lines
                            ->filter(fn (Line $line) => Str::contains($line, $fullMethod))
                            ->first();


        $this->breakpointLine = $this->lines
                            ->skipUntil(fn (Line $line) => $line === $this->initialMethodLine)
                            ->filter(fn (Line $line) => Str::contains($line, $this->possibleMethods))
                            ->first();




        $lastAction = $this->reversedLines()
                        ->skipUntil(fn (Line $line) => $line === $this->breakpointLine)
                        ->skip(1)
                        ->takeUntiL(fn (Line $line) => $line === $this->initialMethodLine)
                        ->reject(fn (Line $line) => $line->isEmpty())
                        ->first();

        return $lastAction;
    }

    public function isLastAction(Line $line): bool
    {
        return Str::contains(trim($line), trim($this->getLastAction()));
    }

    public function forEachLine(callable $closure)
    {
        foreach ($this->lines as $key => $line) {
            $this->currentLineInIteration = $line;
            $closure($line, $key);
        }
    }

    public function testLines(): Collection
    {
        return $this->lines
            ->skipUntil($this->testStartsAtLine)
            ->takeUntil($this->breakpointLine)
            ->reject(fn (Line $line) => $line->isEmpty());
    }

    public function forEachTestLine(callable $closure)
    {
        foreach ($this->testLines() as $line) {
            $closure($line);
        }
    }

    public function addTestLine(Line $line, $final = false): void
    {
        $this->addContentAfterLine($this->lastLineAdded, $line, $final);
    }

    public function addTestLines($lines): void
    {
        collect($lines)->each(fn (Line $line, $key) => $this->addTestLine($line, $line === $lines->last()));
    }

    public function removeLine(Line $line): void
    {
        $this->lines = $this->lines->reject(
            fn (Line $originalLine) =>
             $originalLine == $line
        );
    }

    public function addContentAfterLine(Line $referenceLine, Line $newLine, $final = false): void
    {
        $this->lines = $this->lines->map(function (Line $line, $key) use ($referenceLine, $newLine, $final) {
            if ($line !== $referenceLine) {
                return $line;
            }

            if ($final) {
                $newLine->final();
            }

            $return = [$line, $newLine];

            $this->lastLineAdded = last($return);

            return $return;
        })->flatten();
    }

    public function startWritingTest(): void
    {
        $this->testStartsAtLine = $this->currentLineInIteration;
        $this->writingTest = true;
    }

    public function stopWritingTest(): void
    {
        $this->writingTest = false;
    }

    public function previousLineTo(Line $line, $ignoreHelpers = true): Line
    {
        $lineKey = $this->reversedLines()->search($line);

        return $this->reversedLines()->filter(
            fn (Line $line, $key) =>
            $key > $lineKey && ($ignoreHelpers ? ! $line->isHelper() : true)
        )->first();
    }

    public function isFirstClick(Line $line): bool
    {
        $reversedLines = $this->reversedLines();

        return $this->reversedLines()
                    ->skipUntil(fn (Line $foundLine) => $foundLine === $line)
                    ->skip(1)
                    ->takeUntil(fn (Line $foundLine) => $foundLine === $this->initialMethodLine)
                    ->filter(fn (Line $line) => $line->isClickOrPress())
                    ->isEmpty();
    }

    public function reversedLines(): Collection
    {
        return $this->lines->reverse()->values();
    }

    public function output(): string
    {
        $lines = clone $this->lines;

        $this->fixBreakpoint();

        return tap(
            $this->addNecessaryPausesToLines()
            ->map(fn (Line $line) => $line->__toString())
            ->implode("\n"),
            fn () => $this->lines = $lines
        );
    
        return $this->lines
                    ->map(fn (Line $line) => $line->__toString())
                    ->implode("\n");
    }

    public function addNecessaryPausesToLines(): Collection
    {
        $this->forEachTestLine(function ($line) {
            $previousLine = $this->previousLineTo($line);

            if ($previousLine->isClickOrPress()) {
                $this->addContentAfterLine($previousLine, Line::pause());
            }
        });

        return $this->lines;
    }

    public function fixBreakpoint(): void
    {
        if ($this->breakpointLine->isMacroCall()) {
            $this->previousLineTo($this->breakpointLine)->notFinal();
        }
    }

    protected function generateLines(): Collection
    {
        $lines = explode("\n", $this->content);

        return  collect($lines)->mapInto(Line::class);
    }
}
