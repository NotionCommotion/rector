<?php

declare(strict_types=1);

namespace Rector\PHPUnit\Rector\MethodCall;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use Rector\Rector\AbstractPHPUnitRector;
use Rector\RectorDefinition\CodeSample;
use Rector\RectorDefinition\RectorDefinition;

/**
 * @see https://github.com/FriendsOfPHP/PHP-CS-Fixer/issues/4160
 * @see https://github.com/symfony/symfony/pull/29685/files
 * @see \Rector\PHPUnit\Tests\Rector\MethodCall\UseSpecificWillMethodRector\UseSpecificWillMethodRectorTest
 */
final class UseSpecificWillMethodRector extends AbstractPHPUnitRector
{
    /**
     * @var string[]
     */
    private $nestedMethodToRenameMap = [
        'returnArgument' => 'willReturnArgument',
        'returnCallback' => 'willReturnCallback',
        'returnSelf' => 'willReturnSelf',
        'returnValue' => 'willReturn',
        'returnValueMap' => 'willReturnMap',
        'throwException' => 'willThrowException',
    ];

    public function getDefinition(): RectorDefinition
    {
        return new RectorDefinition('Changes ->will($this->xxx()) to one specific method', [
            new CodeSample(
                <<<'PHP'
class SomeClass extends PHPUnit\Framework\TestCase
{
    public function test()
    {
        $translator = $this->getMockBuilder('Symfony\Component\Translation\TranslatorInterface')->getMock();
        $translator->expects($this->any())
            ->method('trans')
            ->with($this->equalTo('old max {{ max }}!'))
            ->will($this->returnValue('translated max {{ max }}!'));
    }
}
PHP
                ,
                <<<'PHP'
class SomeClass extends PHPUnit\Framework\TestCase
{
    public function test()
    {
        $translator = $this->getMockBuilder('Symfony\Component\Translation\TranslatorInterface')->getMock();
        $translator->expects($this->any())
            ->method('trans')
            ->with('old max {{ max }}!')
            ->willReturnValue('translated max {{ max }}!');
    }
}
PHP
            ),
        ]);
    }

    /**
     * @return string[]
     */
    public function getNodeTypes(): array
    {
        return [MethodCall::class, StaticCall::class];
    }

    /**
     * @param MethodCall|StaticCall $node
     */
    public function refactor(Node $node): ?Node
    {
        if (! $this->isInTestClass($node)) {
            return null;
        }

        $callerNode = $node instanceof StaticCall ? $node->class : $node->var;
        if (! $this->isObjectType($callerNode, 'PHPUnit\Framework\MockObject\Builder\InvocationMocker')) {
            return null;
        }

        if ($this->isName($node->name, 'with')) {
            return $this->processWithCall($node);
        }

        if ($this->isName($node->name, 'will')) {
            return $this->processWillCall($node);
        }

        return null;
    }

    /**
     * @param MethodCall|StaticCall $node
     * @return MethodCall|StaticCall
     */
    private function processWithCall(Node $node): Node
    {
        foreach ($node->args as $i => $argNode) {
            if (! $argNode->value instanceof MethodCall) {
                continue;
            }

            $methodCall = $argNode->value;
            if (! $this->isName($methodCall->name, 'equalTo')) {
                continue;
            }

            $node->args[$i] = $methodCall->args[0];
        }

        return $node;
    }

    /**
     * @param MethodCall|StaticCall $node
     * @return MethodCall|StaticCall|null
     */
    private function processWillCall(Node $node): ?Node
    {
        if (! $node->args[0]->value instanceof MethodCall) {
            return null;
        }

        $nestedMethodCall = $node->args[0]->value;

        foreach ($this->nestedMethodToRenameMap as $oldMethodName => $newParentMethodName) {
            if (! $this->isName($nestedMethodCall->name, $oldMethodName)) {
                continue;
            }

            $node->name = new Identifier($newParentMethodName);

            // move args up
            $node->args = $nestedMethodCall->args;

            return $node;
        }

        return null;
    }
}
