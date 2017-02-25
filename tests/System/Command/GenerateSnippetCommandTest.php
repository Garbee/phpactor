<?php

namespace Phpactor\Tests\System\Command;

use Phpactor\Tests\System\SystemTestCase;

class GenerateSnippetCommandTest extends SystemTestCase
{
    /**
     * @dataProvider provideGenerate
     */
    public function testGenerate($generator, array $options = [])
    {
        $this->markTestSkipped();
        $process = $this->exec(sprintf(
            'generate:snippet %s %s --options=\'%s\'',
            $generator,
            'tests/Functional/Example/ClassOne.php',
            json_encode($options)
        ));
        $this->assertSuccess($process);
    }

    public function provideGenerate()
    {
        return [
            [
                'implement_missing_methods',
            ],
            [
                'implement_missing_properties',
            ],
            [
                'class',
            ],
            [
                'class',
                [
                    'type' => 'trait',
                ]
            ],
        ];
    }
}


