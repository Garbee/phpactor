<?php

namespace Phpactor\Tests\System;

use Symfony\Component\Process\Process;

class SystemTestCase extends \PHPUnit_Framework_TestCase
{
    protected function assertSuccess(Process $process)
    {
        if (true === $process->isSuccessful()) {
            return;
        }

        $this->fail(sprintf(
            'Process exited with code %d: %s %s', $process->getExitCode(), $process->getErrorOutput(), $process->getOutput()
        ));
    }

    protected function exec(string $args)
    {
        chdir(__DIR__ . '/../..');
        $bin = 'bin/phpactor';
        $process = new Process(sprintf(
            '%s %s'
        , $bin, $args));
        $process->run();

        return $process;
    }
}
