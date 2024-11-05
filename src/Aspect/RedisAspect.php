<?php

declare(strict_types=1);

namespace HyperfContrib\OpenTelemetry\Aspect;

use Hyperf\Di\Aop\ProceedingJoinPoint;
use Hyperf\Redis\Redis;
use Hyperf\Stringable\Str;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SemConv\TraceAttributes;

class RedisAspect extends AbstractAspect
{
    public array $classes = [
        Redis::class . '::__call',
    ];

    /**
     * @throws \Hyperf\Di\Exception\Exception
     * @throws \Throwable
     */
    public function process(ProceedingJoinPoint $proceedingJoinPoint)
    {
        if ($this->switcher->isTracingEnabled('redis') === false) {
            return $proceedingJoinPoint->process();
        }

        $args        = $proceedingJoinPoint->getArguments();
        $command     = $args[0];
        $commandFull = $command . ' ' . implode(' ', $args[1]);
        $poolName    = (fn () => $this->poolName ?? 'default')->call($proceedingJoinPoint->getInstance());

        $span = $this->instrumentation->tracer()->spanBuilder('redis ' . $command)
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->startSpan();

        // todo: add more attributes
        $span->setAttributes([
            TraceAttributes::DB_SYSTEM         => 'redis',
            TraceAttributes::DB_OPERATION_NAME => Str::upper($command),
            TraceAttributes::DB_QUERY_TEXT     => $commandFull,
            TraceAttributes::DB_STATEMENT      => $commandFull,
            'hyperf.redis.pool'                => $poolName,
        ]);

        try {
            $result = $proceedingJoinPoint->process();
            $span->setStatus(StatusCode::STATUS_OK);
        } catch (\Throwable $e) {
            $this->spanRecordException($span, $e);

            throw $e;
        } finally {
            $span->end();
        }

        return $result;
    }
}
