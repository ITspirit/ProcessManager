<?php

/**
 * Elements.at
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Enterprise License (PEL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 *  @copyright  Copyright (c) elements.at New Media Solutions GmbH (https://www.elements.at)
 *  @license    http://www.pimcore.org/license     GPLv3 and PEL
 */

namespace Elements\Bundle\ProcessManagerBundle;

use Elements\Bundle\ProcessManagerBundle\Model\Configuration;
use Elements\Bundle\ProcessManagerBundle\Model\MonitoringItem;
use Monolog\Logger;
use Elements\Bundle\ProcessManagerBundle\Executor\Logger\Console;

trait ExecutionTrait
{
    protected $commandObject;

    protected static function getCommand($options)
    {
        global $argv;
        $command = $options['command'] ?: implode(' ', $argv);

        return trim($command);
    }

    /**
     * @param $monitoringId
     * @param array $options
     *
     * @return MonitoringItem
     */
    public static function initProcessManager($monitoringId, $options = [])
    {
        if (!ElementsProcessManagerBundle::getMonitoringItem(false)) {
            if ($monitoringId) {
                $monitoringItem = MonitoringItem::getById($monitoringId);
                ElementsProcessManagerBundle::setMonitoringItem($monitoringItem);
            }

            if ($options['autoCreate'] && !$monitoringItem) {
                $options['command'] = self::getCommand($options);

                $monitoringItem = new MonitoringItem();
                $monitoringItem->setValues($options);

                if ($configId = $monitoringItem->getConfigurationId()) {
                    $config = Configuration::getById($configId);
                    if ($executor = $config->getExecutorClassObject()) {
                        $monitoringItem->setLoggers($executor->getLoggers());
                    }
                }

                unset($options['id']);

                /**
                 * only set console logger if dont pass loggers or the config doesn't have loggers
                 */
                if ($options['loggers'] === null && empty($monitoringItem->getLoggers())) {
                    $monitoringItem->setLoggers([
                        [
                            'logLevel' => 'DEBUG',
                            'simpleLogFormat' => 'on',
                            'class' => Console::class
                        ]
                    ]);
                }

                $monitoringItem->setStatus($monitoringItem::STATUS_INITIALIZING);
                $monitoringItem->setPid(getmypid());
                $monitoringItem->save();
                $monitoringId = $monitoringItem->getId();
                ElementsProcessManagerBundle::setMonitoringItem($monitoringItem);
            }

            $monitoringItem = ElementsProcessManagerBundle::getMonitoringItem();
            if ($monitoringId) {
                if ($monitoringItem) {
                    $config = Configuration::getById($monitoringItem->getConfigurationId());
                    if ($config) {
                        if (!$monitoringItem->getName()) {
                            $monitoringItem->setName($config->getName())->save();
                        }
                        if (!$config->getActive()) {
                            exit('ProcessManager: Config with ID ' . $config->getId().' is disabled - exiting');
                        }
                    }
                    $values = $config ? $config->getExecutorClassObject()->getValues() : $options;
                    if ($values['uniqueExecution']) {
                        self::doUniqueExecutionCheck($config, $options);
                    }
                }

                $options['monitoringItem'] = $monitoringItem;

                if (!PIMCORE_CONSOLE) {
                    register_shutdown_function(function ($arguments) {
                        ElementsProcessManagerBundle::shutdownHandler($arguments);
                    }, $options);
                }
                ElementsProcessManagerBundle::startup($options);

                $monitoringItem->setCurrentStep($options['currentStop'] ?: 1)
                    ->setTotalSteps($options['totalSteps'] ?: 1)->setStatus(MonitoringItem::STATUS_RUNNING)->save();
            }
        }
        \Pimcore\Tool\Console::checkExecutingUser((array)ElementsProcessManagerBundle::getConfig()['general']['additionalScriptExecutionUsers']);

        return ElementsProcessManagerBundle::getMonitoringItem();
    }

    /**
     * @param $config
     * @param $options
     */
    protected static function doUniqueExecutionCheck($config, $options)
    {
        //when we have a config we check for the config id to determine the processes
        if ($config) {
            $processesRunning = $config->getRunningProcesses();
        } else {
            $list = new MonitoringItem\Listing();
            $list->setCondition('command = ? AND pid <> ""', [$options['command']]);
            $processesRunning = [];
            foreach ($list->load() as $item) {
                if ($item->isAlive()) {
                    $processesRunning[] = $item;
                }
            }
        }

        //remove own pid
        foreach ($processesRunning as $i => $item) {
            if ($item->getPid() == getmypid()) {
                unset($processesRunning[$i]);
            }
        }

        if ($count = count($processesRunning)) {
            foreach ($processesRunning as $process) {
                //only delete the item if the other process doesn't use the same monitoring ID
                if ($process->getId() != ElementsProcessManagerBundle::getMonitoringItem()->getId()) {
                    ElementsProcessManagerBundle::getMonitoringItem()->delete();
                }
            }
            ElementsProcessManagerBundle::getMonitoringItem()->getLogger()->info('Another process with the PID ' . getmypid().' started. Exiting Process:' . getmypid());
            ElementsProcessManagerBundle::setMonitoringItem(null);
            exit("\n\nProcessManager: $count ".($count > 1 ? 'processes running' : 'process running'). " - exiting\n\n");
        }
    }

    /**
     * @return MonitoringItem
     */
    public static function getMonitoringItem()
    {
        return ElementsProcessManagerBundle::getMonitoringItem();
    }

    /**
     * @return mixed
     */
    public function getCommandObject()
    {
        return $this->commandObject;
    }

    /**
     * @param mixed $commandObject
     *
     * @return $this
     */
    public function setCommandObject($commandObject)
    {
        $this->commandObject = $commandObject;

        return $this;
    }
}
