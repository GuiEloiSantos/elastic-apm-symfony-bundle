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
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleExceptionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class CommandListener implements EventSubscriberInterface
{
    private $interactor;
    private $config;

    public function __construct(ElasticApmInteractorInterface $interactor, Config $config)
    {
        $this->interactor = $interactor;
        $this->config = $config;
    }

    public static function getSubscribedEvents(): array
    {
        // Check if ConsoleEvents::ERROR constant exists
        $errorConstant = defined('Symfony\Component\Console\ConsoleEvents::ERROR') ?
            ConsoleEvents::ERROR :
            ConsoleEvents::EXCEPTION;

        return [
            ConsoleEvents::COMMAND => ['onConsoleCommand', 0],
            $errorConstant => ['onConsoleError', 0],
        ];
    }

    public function onConsoleCommand(ConsoleCommandEvent $event): void
    {
        $command = $event->getCommand();
        $input = $event->getInput();

        $this->interactor->setTransactionName($command->getName());

        foreach ($input->getOptions() as $key => $value) {
            $key = '--' . $key;
            if (\is_array($value)) {
                foreach ($value as $k => $v) {
                    $this->interactor->addCustomContext($key . '[' . $k . ']', $v);
                }
            } else {
                $this->interactor->addCustomContext($key, $value);
            }
        }

        foreach ($input->getArguments() as $key => $value) {
            if (\is_array($value)) {
                foreach ($value as $k => $v) {
                    $this->interactor->addCustomContext($key . '[' . $k . ']', $v);
                }
            } else {
                $this->interactor->addCustomContext($key, $value);
            }
        }

        $this->interactor->addContextFromConfig();
    }

    /**
     * @param ConsoleErrorEvent|ConsoleExceptionEvent $event
     */
    public function onConsoleError($event): void
    {
        if (!$this->config->shouldExplicitlyCollectCommandExceptions()) {
            return;
        }

        $this->interactor->addContextFromConfig();
        $this->interactor->noticeThrowable($this->getError($event));

        if (null !== $this->getError($event)->getPrevious()) {
            $this->interactor->addContextFromConfig();
            $this->interactor->noticeThrowable($this->getError($event)->getPrevious());
        }
    }

    /**
     * @param ConsoleErrorEvent|ConsoleExceptionEvent $event
     * @return \Throwable|null
     */
    private function getError($event): ?\Throwable
    {
        if (\class_exists(ConsoleErrorEvent::class) && $event instanceof ConsoleErrorEvent) {
            return $event->getError();
        }

        if ($event instanceof ConsoleExceptionEvent) {
            return $event->getException();
        }

        throw new \RuntimeException('Unknown event type');
    }
}
