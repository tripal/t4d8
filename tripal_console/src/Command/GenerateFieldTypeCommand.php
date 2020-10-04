<?php

namespace Drupal\tripal_console\Command;

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Drupal\Console\Core\Command\ContainerAwareCommand;
use Drupal\Console\Core\Generator\GeneratorInterface;
use Drupal\Console\Utils\Validator;
use Drupal\Console\Command\Shared\ModuleTrait;
use Drupal\Console\Command\Shared\ConfirmationTrait;
use Drupal\Console\Core\Command\Command;
use Drupal\Console\Extension\Manager;
use Drupal\Console\Core\Utils\StringConverter;
use Drupal\Console\Core\Utils\ChainQueue;
use Drupal\Core\Field\FieldTypePluginManager;
use Drupal\Console\Core\Utils\TranslatorManager;
use Drupal\tripal_console\Command\TripalCommand;

/**
 * Class GenerateFieldTypeCommand.
 *
 * Drupal\Console\Annotations\DrupalCommand (
 *     extension="tripal_console",
 *     extensionType="module"
 * )
 */
class GenerateFieldTypeCommand extends TripalCommand {

  /**
   * Drupal\Console\Core\Generator\GeneratorInterface definition.
   *
   * @var \Drupal\Console\Core\Generator\GeneratorInterface
   */
  protected $generator;

  /**
   * Contains the YAML parsed output from the command-specific YAML file.
   * @see src/UserText/[command name].yml
   */
  protected $user_text;

  /**
   * Contains the YAML parsed output from the common YAML file.
   * @see src/UserText/commonText.yml
   */
  protected $common_text;

  /**
   * Constructs a new GenerateFieldTypeCommand object.
   */
  public function __construct(GeneratorInterface $generator) {
    $this->generator = $generator;
    $module_path = drupal_get_path('module', 'tripal_console');

    $this->common_text = Yaml::parse(file_get_contents("$module_path/src/UserText/commonText.yml"));
    $this->user_text = Yaml::parse(file_get_contents("$module_path/src/UserText/tripal.generate.fieldType.yml"));

    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  protected function configure() {
    $this
      ->setName('tripal:generate:fieldType')
      ->setDescription($this->user_text['description'])
      ->addOption(
          'module',                                    // full-length option.
          null,                                        // option alias.
          InputOption::VALUE_REQUIRED,                 // Constraints.
          $this->common_text['option-help']['module']  // Help Text.
      )
      ->addOption(
          'type-class',
          null,
          InputOption::VALUE_REQUIRED,
          $this->user_text['option-help']['type-class']
      )
      ->addOption(
          'type-label',
          null,
          InputOption::VALUE_OPTIONAL,
          $this->user_text['option-help']['type-label']
      )
      ->addOption(
          'type-plugin-id',
          null,
          InputOption::VALUE_OPTIONAL,
          $this->user_text['option-help']['type-plugin-id']
      )
      ->addOption(
          'type-description',
          null,
          InputOption::VALUE_OPTIONAL,
          $this->user_text['option-help']['type-description']
      )
      ->addOption(
          'default-widget',
          null,
          InputOption::VALUE_OPTIONAL,
          $this->user_text['option-help']['default-widget']
      )
      ->addOption(
          'default-formatter',
          null,
          InputOption::VALUE_OPTIONAL,
          $this->user_text['option-help']['default-formatter']
      )
      ->setAliases(['trpgen-fieldType']);
  }

  protected function promptOption($input, $option) {
    $optionValue = $input->getOption($option);
    if (!$optionValue) {
        $question = $this->user_text['option-help'][$option];
        if (!$question) {
          $question = $this->common_text['option-help'][$option]; }
        $optionValue = $this->getIo()->ask($question);
        $input->setOption($option, $optionValue);
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function interact(InputInterface $input, OutputInterface $output) {

    $this->promptOption($input, 'module');
    $this->promptOption($input, 'type-class');
    $this->promptOption($input, 'type-label');
    $this->promptOption($input, 'type-plugin-id');
    $this->promptOption($input, 'type-description');
    $this->promptOption($input, 'default-widget');
    $this->promptOption($input, 'default-formatter');
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output) {
    //$this->getIo()->info('execute');
    $this->getIo()->warning('Not Yet Implemented.');
    $this->generator->generate([]);
  }

}
