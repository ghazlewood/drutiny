<?php

namespace Drutiny\Command;

use Drutiny\Container;
use Drutiny\Profile;
use Drutiny\Profile\PolicyDefinition;
use Drutiny\RemediableInterface;
use Drutiny\Report\Format;
use Drutiny\Report\ProfileRunReport;
use Drutiny\Sandbox\Sandbox;
use Drutiny\Target\Registry as TargetRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;


/**
 *
 */
class PolicyAuditCommand extends Command {

  /**
   * @inheritdoc
   */
  protected function configure() {
    $this
      ->setName('policy:audit')
      ->setDescription('Run a single policy audit against a site.')
      ->addArgument(
        'policy',
        InputArgument::REQUIRED,
        'The name of the check to run.'
      )
      ->addArgument(
        'target',
        InputArgument::REQUIRED,
        'The target to run the check against.'
      )
      ->addOption(
        'set-parameter',
        'p',
        InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
        'Set parameters for the check.',
        []
      )
      ->addOption(
        'remediate',
        'r',
        InputOption::VALUE_NONE,
        'Allow failed checks to remediate themselves if available.'
      )
      ->addOption(
        'uri',
        'l',
        InputOption::VALUE_OPTIONAL,
        'Provide URLs to run against the target. Useful for multisite installs. Accepts multiple arguments.'
      )
      ->addOption(
        'reporting-period-start',
        null,
        InputOption::VALUE_OPTIONAL,
        'The starting point in time to report from. Can be absolute or relative. Defaults to 24 hours before the current hour.',
        date('Y-m-d H:00:00', strtotime('-24 hours'))
      )
      ->addOption(
        'reporting-period-end',
        null,
        InputOption::VALUE_OPTIONAL,
        'The end point in time to report to. Can be absolute or relative. Defaults to the current hour.',
        date('Y-m-d H:00:00')
      );
  }

  /**
   * @inheritdoc
   */
  protected function execute(InputInterface $input, OutputInterface $output) {
    // Ensure Container logger uses the same verbosity.
    Container::setVerbosity($output->getVerbosity());

    // Setup any parameters for the check.
    $parameters = [];
    foreach ($input->getOption('set-parameter') as $option) {
      list($key, $value) = explode('=', $option, 2);
      // Using Yaml::parse to ensure datatype is correct.
      $parameters[$key] = Yaml::parse($value);
    }

    $name = $input->getArgument('policy');
    $profile = new Profile();
    $profile->setTitle('Policy Audit: ' . $name)
            ->setName($name)
            ->setFilepath('/dev/null')
            ->addPolicyDefinition(
              PolicyDefinition::createFromProfile($name, 0, [
                'parameters' => $parameters
              ])
            )
            ->addFormatOptions(Format::create('terminal', [
              'content' => Yaml::parseFile(dirname(__DIR__) . '/Report/templates/content/policy.markdown.yml'),
              'output' => $output,
            ]));

    // Setup the target.
    $target = TargetRegistry::loadTarget($input->getArgument('target'));
    $result = [];

    $start = new \DateTime($input->getOption('reporting-period-start'));
    $end   = new \DateTime($input->getOption('reporting-period-end'));
    $profile->setReportingPeriod($start, $end);

    foreach ($profile->getAllPolicyDefinitions() as $definition) {
      $policy = $definition->getPolicy();

      // Generate the sandbox to execute the check.
      $sandbox = new Sandbox($target, $policy);
      $sandbox->setReportingPeriod($start, $end);

      if ($uri = $input->getOption('uri')) {
        $target->setUri($uri);
      }

      $response = $sandbox->run();

      // Attempt remeidation.
      if (!$response->isSuccessful() && $input->getOption('remediate') && ($sandbox->getAuditor() instanceof RemediableInterface)) {
        $response = $sandbox->remediate();
      }

      $result[$policy->get('name')] = $response;
    }

    $profile->getFormatOption('markdown')
            ->render($profile, $sandbox->getTarget(), [$result]);

    // $report = new ProfileRunReport($profile, $sandbox->getTarget(), $result);
    // $report->render($input, $output);
  }

}
