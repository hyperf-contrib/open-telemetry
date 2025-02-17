<?php

declare(strict_types=1);

namespace HyperfContrib\OpenTelemetry\Listener;

use Carbon\Carbon;
use Hyperf\Command\Event\AfterExecute;
use Hyperf\Command\Event\BeforeHandle;
use function Hyperf\Coroutine\defer;
use Hyperf\Event\Contract\ListenerInterface;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SemConv\TraceAttributes;

/**
 * @property \Symfony\Component\Console\Input\InputInterface $input
 */
class CommandListener extends InstrumentationListener implements ListenerInterface
{

    public function listen(): array
    {
        return [
            BeforeHandle::class,
            AfterExecute::class,
        ];
    }

    public function process(object $event): void
    {
        if (!$this->switcher->isTracingEnabled('command')) {
            return;
        }

        match ($event::class) {
            BeforeHandle::class => $this->onBeforeHandle($event),
            AfterExecute::class => $this->onAfterExecute($event),
            default             => null,
        };
    }

    protected function onBeforeHandle(BeforeHandle $event): void
    {
        $args = (fn () => $this->input->getArguments())->call($event->getCommand());

        $parent = Context::getCurrent();
        $span   = $this->instrumentation->tracer()->spanBuilder($event->getCommand()->getName())
            ->setSpanKind(SpanKind::KIND_INTERNAL)
            ->setAttributes([
                TraceAttributes::PROCESS_COMMAND         => $event->getCommand()->getName(),
                TraceAttributes::PROCESS_COMMAND_ARGS    => $args,
                TraceAttributes::PROCESS_CREATION_TIME   => Carbon::now()->toIso8601String(),
                TraceAttributes::PROCESS_EXECUTABLE_NAME => $event->getCommand()->getName(),
            ])
            ->startSpan();

        Context::storage()->attach($span->storeInContext($parent));
    }

    protected function onAfterExecute(AfterExecute $event): void
    {
        if (!$scope = Context::storage()->scope()) {
            return;
        }
        defer(function () use ($scope) {
            $scope->detach();
        });

        $span = Span::fromContext($scope->context());
        if (!$span->isRecording()) {
            return;
        }
        defer(function () use ($span) {
            $span->end();
        });

        // attributes
        $span->setAttributes([
            //TraceAttributes::PROCESS_EXIT_CODE => $event->getCommand()->getExitCode(), // not available in AfterExecute event
            TraceAttributes::PROCESS_EXIT_TIME => Carbon::now()->toIso8601String(),
        ]);

        // status
        if (($e = $event->getThrowable()) !== null) {
            $this->spanRecordException($span, $e);
        } else {
            $span->setStatus(StatusCode::STATUS_OK);
        }
    }
}
