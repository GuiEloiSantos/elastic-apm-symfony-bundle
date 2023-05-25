<?php

declare(strict_types=1);

/*
 * This file is part of Ekino New Relic bundle.
 *
 * (c) Ekino - Thomas Rabaix <thomas.rabaix@ekino.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ElasticApmBundle\Listener;

use ElasticApmBundle\Interactor\Config;
use ElasticApmBundle\Interactor\ElasticApmInteractorInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Listen to exceptions dispatched by Symfony to log them to Elastic APM.
 */
class ExceptionListener implements EventSubscriberInterface
{
    const CUSTOM_APM_EXCEPTION_LABEL = 'custom.apm.exception';
    private $interactor;
    private $ignoredExceptions;

    public function __construct(ElasticApmInteractorInterface $interactor, array $ignoredExceptions)
    {
        $this->interactor = $interactor;
        $this->ignoredExceptions = $ignoredExceptions;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onKernelException', 0],
        ];
    }

    /**
     * @param GetResponseForExceptionEvent|ExceptionEvent $event
     */
    public function onKernelException(KernelExceptionEvent $event): void
    {
        $exception = \method_exists($event, 'getThrowable') ? $event->getThrowable() : $event->getException();
        if (!$exception instanceof HttpExceptionInterface && !in_array(
                get_class($exception),
                $this->ignoredExceptions
            )) {
            $this->interactor->addContextFromConfig();
            $this->interactor->noticeThrowable($exception);
        }

        if ($exception instanceof \Throwable) {
            // always decorate the existing transaction with the exception
            $this->interactor->addLabel($this->getApmExceptionLabel('.exception'), get_class($exception));
            $this->interactor->addLabel($this->getApmExceptionLabel('.message'), $exception->getMessage());
            $this->interactor->addLabel($this->getApmExceptionLabel('.code'), $exception->getCode());
            // get line where the exception is thrown
            $trace = $exception->getTrace();
            if (isset($trace[0]['line'])) {
                $this->interactor->addLabel($this->getApmExceptionLabel('.file'), $trace[0]['file']);
                $this->interactor->addLabel($this->getApmExceptionLabel('.line'), $trace[0]['line']);
            }
        }
    }

    /**
     * @return string
     */
    private function getApmExceptionLabel(string $suffix): string
    {
        return self::CUSTOM_APM_EXCEPTION_LABEL . $suffix;
    }
}

if (!\class_exists(KernelExceptionEvent::class)) {
    if (\class_exists(ExceptionEvent::class)) {
        \class_alias(ExceptionEvent::class, KernelExceptionEvent::class);
    } else {
        \class_alias(GetResponseForExceptionEvent::class, KernelExceptionEvent::class);
    }
}
