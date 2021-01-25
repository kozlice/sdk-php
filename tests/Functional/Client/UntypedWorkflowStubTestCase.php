<?php

/**
 * This file is part of Temporal package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Temporal\Tests\Functional\Client;

use Temporal\Client\WorkflowOptions;
use Temporal\Exception\Client\WorkflowExecutionAlreadyStartedException;
use Temporal\Exception\Client\WorkflowFailedException;
use Temporal\Exception\Failure\CanceledFailure;
use Temporal\Exception\Failure\TerminatedFailure;
use Temporal\Exception\IllegalStateException;

/**
 * @group client
 * @group functional
 */
class UntypedWorkflowStubTestCase extends ClientTestCase
{
    public function testUntypedStartAndWaitResult()
    {
        $w = $this->createClient();
        $simple = $w->newUntypedWorkflowStub('SimpleWorkflow');

        $e = $simple->start(['hello world']);
        $this->assertNotEmpty($e->id);
        $this->assertNotEmpty($e->runId);

        $this->assertSame('HELLO WORLD', $simple->getResult());
    }

    public function testUntypedStartViaClient()
    {
        $w = $this->createClient();
        $simple = $w->newUntypedWorkflowStub('SimpleWorkflow');
        $r = $w->start($simple, 'test');

        $this->assertNotEmpty($r->getExecution()->id);
        $this->assertNotEmpty($r->getExecution()->runId);

        $this->assertSame('TEST', $r->getResult());
    }

    public function testStartWithSameID()
    {
        $w = $this->createClient();
        $simple = $w->newUntypedWorkflowStub('SimpleWorkflow');

        $e = $simple->start(['hello world']);
        $this->assertNotEmpty($e->id);
        $this->assertNotEmpty($e->runId);

        $simple2 = $w->newUntypedWorkflowStub('SimpleWorkflow', WorkflowOptions::new()
            ->withWorkflowId($e->id));

        $this->expectException(WorkflowExecutionAlreadyStartedException::class);
        $simple2->start(['hello world']);
    }

    public function testSignalWorkflowAndGetResult()
    {
        $w = $this->createClient();
        $simple = $w->newUntypedWorkflowStub('SimpleSignalledWorkflowWithSleep');

        $e = $simple->start();
        $this->assertNotEmpty($e->id);
        $this->assertNotEmpty($e->runId);

        $simple->signal('add', [-1]);

        $this->assertSame(-1, $simple->getResult());
    }

    public function testSignalWithStart()
    {
        $w = $this->createClient();
        $simple = $w->newUntypedWorkflowStub('SimpleSignalledWorkflowWithSleep');

        $e = $simple->signalWithStart('add', [-1]);
        $this->assertNotEmpty($e->id);
        $this->assertNotEmpty($e->runId);

        $simple->signal('add', [-1]);

        $this->assertSame(-2, $simple->getResult());
    }

    public function testSignalWithStartAlreadyStarted()
    {
        $w = $this->createClient();
        $simple = $w->newUntypedWorkflowStub('SimpleSignalledWorkflowWithSleep');

        $e = $simple->signalWithStart('add', [-1]);
        $this->assertNotEmpty($e->id);
        $this->assertNotEmpty($e->runId);

        $simple->signal('add', [-1]);

        $this->assertSame(-2, $simple->getResult());

        $simple2 = $w->newUntypedWorkflowStub('SimpleWorkflow', WorkflowOptions::new()
            ->withWorkflowId($e->id));

        $this->expectException(WorkflowExecutionAlreadyStartedException::class);
        $simple2->signalWithStart('add', [-1]);
    }

    public function testSignalNotStarted()
    {
        $w = $this->createClient();
        $simple = $w->newUntypedWorkflowStub('SimpleSignalledWorkflowWithSleep');

        $this->expectException(IllegalStateException::class);
        $simple->signal('add', [-1]);
    }

    public function testQueryNotStarted()
    {
        $w = $this->createClient();
        $simple = $w->newUntypedWorkflowStub('SimpleSignalledWorkflowWithSleep');

        $this->expectException(IllegalStateException::class);
        $simple->query('get');
    }

    public function testQueryWorkflow()
    {
        $w = $this->createClient();
        $simple = $w->newUntypedWorkflowStub('QueryWorkflow');

        $e = $simple->start();
        $this->assertNotEmpty($e->id);
        $this->assertNotEmpty($e->runId);

        $simple->signal('add', [88]);

        $this->assertSame(88, $simple->query('get')->getValue(0));
        $this->assertSame(88, $simple->getResult());
    }

    public function testStartAsNewEventTrailing()
    {
        $w = $this->createClient();
        $simple = $w->newUntypedWorkflowStub('ContinuableWorkflow');

        $e = $simple->start([1]);
        $this->assertNotEmpty($e->id);
        $this->assertNotEmpty($e->runId);

        $this->assertSame('OK6', $simple->getResult());
    }

    public function testCancelled()
    {
        $w = $this->createClient();
        $simple = $w->newUntypedWorkflowStub('SimpleSignalledWorkflowWithSleep');

        $e = $simple->start([-1]);
        $this->assertNotEmpty($e->id);
        $this->assertNotEmpty($e->runId);

        $simple->cancel();
        try {
            $simple->getResult();
        } catch (WorkflowFailedException $e) {
            $this->assertInstanceOf(CanceledFailure::class, $e->getPrevious());
        }
    }

    public function testTerminated()
    {
        $w = $this->createClient();
        $simple = $w->newUntypedWorkflowStub('SimpleSignalledWorkflowWithSleep');

        $e = $simple->start([-1]);
        $this->assertNotEmpty($e->id);
        $this->assertNotEmpty($e->runId);

        $simple->terminate('user triggered');
        try {
            $simple->getResult();
        } catch (WorkflowFailedException $e) {
            $this->assertInstanceOf(TerminatedFailure::class, $e->getPrevious());
        }
    }
}