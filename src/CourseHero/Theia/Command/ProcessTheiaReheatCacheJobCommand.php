<?php

namespace CourseHero\TheiaBundle\Command;

use CourseHero\QueueBundle\Component\QueueInterface;
use CourseHero\QueueBundle\Component\QueueMessageInterface;
use CourseHero\QueueBundle\Constant\Queue;
use CourseHero\QueueBundle\Service\QueueService;
use CourseHero\StudyGuideBundle\Service\StudyGuideConnectionService;
use CourseHero\TheiaBundle\Service\TheiaProviderService;
use CourseHero\UtilsBundle\Command\AbstractPerpetualCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class ProcessTheiaReheatCacheJobCommand
 * @package CourseHero\TheiaBundle\Command
 *
 * Command used to process the Theia cache reheat jobs
 */
class ProcessTheiaReheatCacheJobCommand extends AbstractPerpetualCommand
{
    const CMD_NAME = 'ch:theia:job';

    /** @var  QueueInterface */
    protected $queue;

    /** @var \Theia\Client */
    protected $theiaClient;

    /** @var array */
    protected $handlers;

    protected function configure()
    {
        $this->setName(self::CMD_NAME)
            ->setDescription('Processes a Theia cache reheat job');
    }

    /**
     * @inheritdoc
     */
    protected function setup()
    {
        $this->queue = $this->getQueueService()->getQueue($this->getQueueName());
        $this->theiaClient = $this->getTheiaProviderService()->getClient();
        $studyGuideConnectionService = $this->getStudyGuideConnectionService();

        $this->handlers = [
            StudyGuideTheiaJobHandler::$componentLibrary => new StudyGuideTheiaJobHandler($this->theiaClient, new ReheatCacheJobCreator($this->queue), $studyGuideConnectionService),
        ];
    }

    /**
     * @inheritdoc
     */
    public function singleRun(InputInterface $input, OutputInterface $output)
    {
        try {
            $message = $this->queue->receiveMessage() ?: null;
            if (!$message) {
                return; // if no more messages just return and restart for now
            }

            $this->write("Job processing started");
            $this->processJob($message);
            $this->write("Finished job\n");

            // relying on dead letter queue + long visibility timeout to avoid double-processing jobs / reprocessing err'd jobs
            $this->queue->deleteMessage($message);
        } catch (\Throwable $e) {
            $this->write("Job failed {$e->getMessage()}");
            $this->getTheiaProviderService()->sendSlackMessage("Caught error: {$e->getMessage()}");
        }
    }

    /**
     * @param QueueMessageInterface $message
     * @throws \Exception
     */
    protected function processJob(QueueMessageInterface $message)
    {
        $body = $message->getBody();
        $attrs = $message->getAttributes();
        $componentLibrary = $attrs['ComponentLibrary']['StringValue'];
        $jobHandler = $this->handlers[$componentLibrary];
        $type = $attrs['Type']['StringValue'];

        $attrsAsString = json_encode($attrs, JSON_PRETTY_PRINT);
        $this->write("Processing $type with $attrsAsString");
        if ($type !== 'render-job') {
            $bodyAsString = json_encode($body, JSON_PRETTY_PRINT);
            $this->write("Body: $bodyAsString");
        }

        switch ($type) {
            case 'new-build-job':
                $jobHandler->processNewBuildJob($body['builtAt'], $body['commitHash']);
                break;
            case 'producer-job':
                $jobHandler->processProducerJob($body['producerGroup'], $body['jobParams'] ?? []);
                break;
            case 'render-job':
                // Don't use jms deserializer here because the code that creates render-jobs uses the jms serializer. So, the data is in the exact structure we want already
                $props = $body;
                $component = $attrs['Component']['StringValue'];
                $jobHandler->processRenderJob($component, $props);
                break;
            default:
                throw new \Exception("unexpected job type: $type");
        }
    }

    protected function getQueueName(): string
    {
        $environment = $this->getContainer()->getParameter('environment');
        return 'production' === $environment ? Queue::THEIA_REHEAT_JOBS : Queue::THEIA_REHEAT_JOBS_DEV;
    }

    protected function getQueueService(): QueueService
    {
        return $this->getContainer()->get(QueueService::SERVICE_ID);
    }

    protected function getStudyGuideConnectionService(): StudyGuideConnectionService
    {
        return $this->getContainer()->get(StudyGuideConnectionService::SERVICE_ID);
    }

    protected function getTheiaProviderService(): TheiaProviderService
    {
        return $this->getContainer()->get(TheiaProviderService::SERVICE_ID);
    }
}