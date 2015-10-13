<?php
namespace AWSManager\Command;

use Aws\Ec2\Ec2Client;
use Aws\Ec2\Exception\Ec2Exception;
use Symfony\Component\Console\Command\Command;
use Aws\S3\S3Client;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;

class SnapshotCommand extends Command {

	/**
	 * @var \Symfony\Component\Console\Input\InputInterface
	 */
	protected $input;

	/**
	 * @var \Symfony\Component\Console\Output\OutputInterface
	 */
	protected $output;

	/**
	 * @var Ec2Client
	 */
	protected $ec2Client;

	public function __construct($name = NULL) {
		parent::__construct($name);
		$options = [
			'region' => 'eu-west-1',
			'version' => '2015-10-01',
		];
		$this->ec2Client = new Ec2Client($options);

	}

	/**
	 *
	 */
	protected function configure() {
		parent::configure();
		$this->setName('snapshot')
			->setDescription('AWS Snapshow handling')
			->addArgument('action', \Symfony\Component\Console\Input\InputArgument::REQUIRED, 'Set the action to do')
			->addArgument('volume', InputArgument::OPTIONAL, 'Volume to snapshot');
	}

	/**
	 * @param \Symfony\Component\Console\Input\InputInterface $input
	 * @param \Symfony\Component\Console\Output\OutputInterface $output
	 * @throws \Exception
	 * @return null
	 */
	protected function execute(\Symfony\Component\Console\Input\InputInterface $input, \Symfony\Component\Console\Output\OutputInterface $output) {
		$this->input = $input;
		$this->output = $output;
		$actions = array('listVolumes', 'takeSnapshot', 'listSnapshots', 'deleteOldAutocreatedSnapshots');

		if (in_array($this->input->getArgument('action'), $actions)) {
			call_user_func(array($this, $this->input->getArgument('action') . 'Action'));
		}
	}

	/**
	 *
	 */
	protected function listSnapshotsAction() {
		if ($this->input->hasArgument('volume') === FALSE) {
			throw new InvalidArgumentException('Please specify the volume to list existing snapshots for');
		}
		$volumeId = $this->input->getArgument('volume');
		$this->output->write(sprintf('Listing snapshot of %s' . PHP_EOL, $volumeId));

		$result = $this->ec2Client->describeSnapshots(array(
			'Filters' => array(
				array('Name' => 'volume-id', 'Values' => array($volumeId))
			)
		));
		$this->output->writeln(sprintf('The volume %s has %d snapshots', $volumeId, count($result['Snapshots'])));
		foreach ($result['Snapshots'] as $snapshot) {
			$this->output->writeln(sprintf(' - %s (%s) on %s Description %s', $snapshot['SnapshotId'], $snapshot['State'], $snapshot['StartTime']->format('d/m-Y H:i'), $snapshot['Description']));
		}
	}

	/**
	 *
	 */
	protected function deleteOldAutocreatedSnapshotsAction() {
		$this->output->writeln('Finding all snapshots that are tagged with AutoPruve=True');
		$result = $this->ec2Client->describeSnapshots(array(
			'Filters' => array(
				array('Name' => 'tag:AutoPrune', 'Values' => array('True'))
			)
		));
		$doDelete = FALSE;
		$offsetInSeconds = 86400 * 7;
		foreach ($result['Snapshots'] as $snapshot) {
			$this->output->writeln(sprintf(' - %s (%s) on %s Description "%s"', $snapshot['SnapshotId'], $snapshot['State'], $snapshot['StartTime']->format('d/m-Y H:i'), $snapshot['Description']));
			if (time() - $snapshot['StartTime']->getTimestamp() > $offsetInSeconds) {
				$this->output->writeln('-- Snapshot overdue. Deleting');
				$this->ec2Client->deleteSnapshot(array(
					'DryRun' => false,
					'SnapshotId' => $snapshot['SnapshotId']
				));
			} else {
				$this->output->writeln(' -- Snapshot is still valid. Keeping.');
			}
		}

		//AutoCreated
	}

	/**
	 *
	 */
	protected function takeSnapshotAction() {
		if ($this->input->hasArgument('volume') === FALSE) {
			throw new InvalidArgumentException('Please specify the volume to snapshot');
		}
		$volumeId = $this->input->getArgument('volume');
		$this->output->write(sprintf('Taking snapshot of %s' . PHP_EOL, $volumeId));
		try {
			$volumeInformation = $this->ec2Client->describeVolumes(array('VolumeIds' => array($volumeId)));
			$now = new \DateTime();
			$result = $this->ec2Client->createSnapshot(array(
				'VolumeId' => $volumeId,
				'Description' => 'Automatic snapshot take on ' . $now->format('d/m-Y'),
				'DryRun' => false
			));
			$newSnapshotIt = $result['SnapshotId'];

			$this->ec2Client->createTags(array(
				'Resources' => array($newSnapshotIt),
				'Tags' => array(
					array('Key' => 'AutoPrune', 'Value' => 'True')
				)
			));

		} catch (Ec2Exception $e) {
			if ($e->getAwsErrorCode() == 'InvalidVolume.NotFound') {
				$this->output->writeln('Unable to finde volue' . $volumeId);
			} else {
				$this->output->writeln('General AWS Error ' . $e->getMessage());
			}
		}

	}

	/**
	 *
	 */
	protected function listVolumesAction() {
		$this->output->write('Volumes available ' . PHP_EOL);

		$options = [
			'region' => 'eu-west-1',
			'version' => '2015-10-01',
		];

		foreach ($this->ec2Client->describeVolumes()['Volumes'] as $volumeInformation) {
			$this->output->write(sprintf(' - VolumeID: %s' . PHP_EOL, $volumeInformation['VolumeId']));
			if (is_array($volumeInformation['Tags'])) {
				foreach ($volumeInformation['Tags'] as $tagInformation) {
					$this->output->write(sprintf(' -- Tag: %s => %s' . PHP_EOL, $tagInformation['Key'], $tagInformation['Value']));
				}
			}
			if (isset($volumeInformation['Attachments']) and is_array($volumeInformation['Attachments'])) {
				foreach($volumeInformation['Attachments'] as $attachmentInformation) {
					$this->output->write(sprintf(' -- Attached to %s (%s)' . PHP_EOL, $attachmentInformation['InstanceId'], $attachmentInformation['Device']));
				}
			} else {
				$this->output->write(' -- Not attached.' . PHP_EOL);
			}
		}

	}



}