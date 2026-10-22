<?php
/**
 * A fake AI provider for the core AI client: OpenAI compatible, one text model, requests to api.example.com that
 * tests answer through pre_http_request. Nothing leaves the test.
 *
 * @package LeitviaStudio
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Test double: provider and model belong together; method names come from the AI client.

use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\Contracts\ProviderInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Enums\RequestAuthenticationMethod;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleTextGenerationModel;

/**
 * Provider, its availability and its model directory in one; $configured switches the connector on and off.
 */
final class Test_Leitvia_Ai_Provider implements ProviderInterface, ProviderAvailabilityInterface, ModelMetadataDirectoryInterface {

	public const URL = 'https://api.example.com/v1/';

	/**
	 * Whether the connector counts as set up.
	 *
	 * @var bool
	 */
	public static bool $configured = false;

	/**
	 * Provider metadata.
	 */
	public static function metadata(): ProviderMetadata {
		return new ProviderMetadata( 'leitvia-test', 'Leitvia Test', ProviderTypeEnum::cloud(), null, RequestAuthenticationMethod::apiKey() );
	}

	/**
	 * The model.
	 *
	 * @param string           $model_id     Model ID.
	 * @param ModelConfig|null $model_config Model config.
	 */
	public static function model( string $model_id, ?ModelConfig $model_config = null ): ModelInterface {
		$model = new Test_Leitvia_Ai_Model( ( new self() )->getModelMetadata( $model_id ), self::metadata() );
		if ( $model_config ) {
			$model->setConfig( $model_config );
		}
		return $model;
	}

	/**
	 * Availability.
	 */
	public static function availability(): ProviderAvailabilityInterface {
		return new self();
	}

	/**
	 * Model directory.
	 */
	public static function modelMetadataDirectory(): ModelMetadataDirectoryInterface {
		return new self();
	}

	/**
	 * Whether the connector is set up.
	 */
	public function isConfigured(): bool {
		return self::$configured;
	}

	/**
	 * The one model, supporting every option.
	 *
	 * @return list<ModelMetadata>
	 */
	public function listModelMetadata(): array {
		return array( $this->getModelMetadata( 'test-model' ) );
	}

	/**
	 * Whether the model exists.
	 *
	 * @param string $model_id Model ID.
	 */
	public function hasModelMetadata( string $model_id ): bool {
		return 'test-model' === $model_id;
	}

	/**
	 * Metadata of the model.
	 *
	 * @param string $model_id Model ID.
	 */
	public function getModelMetadata( string $model_id ): ModelMetadata {
		return new ModelMetadata(
			$model_id,
			'Test model',
			array( CapabilityEnum::textGeneration(), CapabilityEnum::chatHistory() ),
			array_map( static fn( OptionEnum $option ): SupportedOption => new SupportedOption( $option ), OptionEnum::cases() )
		);
	}
}

/**
 * OpenAI compatible text model of the fake provider.
 */
final class Test_Leitvia_Ai_Model extends AbstractOpenAiCompatibleTextGenerationModel {

	/**
	 * Request to the fake API.
	 *
	 * @param HttpMethodEnum $method  Method.
	 * @param string         $path    Path.
	 * @param array          $headers Headers.
	 * @param mixed          $data    Data.
	 */
	protected function createRequest( HttpMethodEnum $method, string $path, array $headers = array(), $data = null ): Request {
		return new Request( $method, Test_Leitvia_Ai_Provider::URL . $path, $headers, $data, $this->getRequestOptions() );
	}
}
