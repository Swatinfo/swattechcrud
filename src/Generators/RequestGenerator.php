<?php

namespace SwatTech\Crud\Generators;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use SwatTech\Crud\Analyzers\DatabaseAnalyzer;
use SwatTech\Crud\Contracts\GeneratorInterface;
use SwatTech\Crud\Helpers\StringHelper;

/**
 * RequestGenerator
 *
 * This class is responsible for generating form request classes for validation
 * and authorization in Laravel applications.
 *
 * @package SwatTech\Crud\Generators
 */
class RequestGenerator implements GeneratorInterface
{
    /**
     * The string helper instance.
     *
     * @var StringHelper
     */
    protected $stringHelper;

    /**
     * The database analyzer instance.
     *
     * @var DatabaseAnalyzer
     */
    protected $databaseAnalyzer;

    /**
     * The list of generated files.
     *
     * @var array
     */
    protected $generatedFiles = [];

    /**
     * Request configuration options.
     *
     * @var array
     */
    protected $options = [];

    /**
     * Create a new RequestGenerator instance.
     *
     * @param StringHelper $stringHelper
     * @param DatabaseAnalyzer $databaseAnalyzer
     */
    public function __construct(StringHelper $stringHelper, DatabaseAnalyzer $databaseAnalyzer)
    {
        $this->stringHelper = $stringHelper;
        $this->databaseAnalyzer = $databaseAnalyzer;

        // Load default configuration options
        $this->options = Config::get('crud.requests', []);
    }

    /**
     * Generate form request files for the specified database table.
     *
     * @param string $table The database table name
     * @param array $options Options for request generation
     * @return array Array of generated file paths
     */
    public function generate(string $table, array $options = []): array
    {
        // Merge custom options with defaults
        $this->options = array_merge($this->options, $options);

        // Reset generated files
        $this->generatedFiles = [];

        // Get table columns
        $columns = $this->databaseAnalyzer->getTableColumns($table);

        // Default requests to generate
        $actions = $this->options['actions'] ?? ['store', 'update'];

        // Generate a request for each action
        foreach ($actions as $action) {
            $className = $this->getClassName($table, $action);
            $content = $this->buildClass($table, $action, $columns);

            $filePath = $this->getPath() . '/' . $className . '.php';

            // Create directory if it doesn't exist
            $directory = dirname($filePath);
            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            // Write the file
            file_put_contents($filePath, $content);
            $this->generatedFiles[] = $filePath;
        }

        return $this->generatedFiles;
    }

    /**
     * Get the class name for the request.
     *
     * @param string $table The database table name
     * @param string $action The CRUD action (store, update, etc.)
     * @return string The request class name
     */
    public function getClassName(string $table, string $action = ''): string
    {
        $modelName = Str::studly(Str::singular($table));
        $actionName = Str::studly($action);

        return $modelName . $actionName . 'Request';
    }

    /**
     * Get the namespace for the request.
     *
     * @return string The request namespace
     */
    public function getNamespace(): string
    {
        return Config::get('crud.namespaces.requests', 'App\\Http\\Requests');
    }

    /**
     * Get the file path for the request.
     *
     * @return string The request file path
     */
    public function getPath(string $path = ""): string
    {
        return base_path(Config::get('crud.paths.requests', 'app/Http/Requests'));
    }

    /**
     * Get the stub template content for request generation.
     *
     * @return string The stub template content
     */
    public function getStub(string $view = ""): string
    {
        $customStubPath = resource_path('stubs/crud/request.stub');

        if (Config::get('crud.stubs.use_custom', false) && file_exists($customStubPath)) {
            return file_get_contents($customStubPath);
        }

        return file_get_contents(__DIR__ . '/../stubs/request.stub');
    }

    /**
     * Build the request class based on options.
     *
     * @param string $table The database table name
     * @param string $action The CRUD action (store, update, etc.)
     * @param array $columns The table columns
     * @return string The generated request content
     */
    public function buildClass(string $table, string $action, array $columns): string
    {
        $className = $this->getClassName($table, $action);
        $namespace = $this->getNamespace();
        $modelClass = Str::studly(Str::singular($table));
        $modelNamespace = Config::get('crud.namespaces.models', 'App\\Models');
        $stub = $this->getStub();

        // Setup authorization method
        $authorization = $this->setupAuthorizationMethod();

        // Generate validation rules
        $validationRules = $this->generateValidationRules($table, $action, $columns);

        // Generate custom error messages
        $errorMessages = $this->generateCustomErrorMessages($validationRules);

        // Setup custom validators
        $customValidators = $this->setupCustomValidators();

        // Generate validation attributes
        $validationAttributes = $this->generateValidationAttributes($columns);

        // Setup after validation hooks
        $afterValidationHooks = $this->setupAfterValidationHooks();

        // Generate prepare for validation method
        $prepareForValidation = $this->generatePrepareForValidationMethod();

        // Setup sanitization
        $sanitization = $this->setupSanitization();

        // Generate rule objects
        $ruleObjects = $this->generateRuleObjects($this->options['complex_rules'] ?? []);

        // Get required imports
        $imports = $this->getRequiredImports($action);

        // Replace stub placeholders
        return str_replace([
            '{{namespace}}',
            '{{imports}}',
            '{{class}}',
            '{{authorization}}',
            '{{rules}}',
            '{{messages}}',
            '{{attributes}}',
            '{{customValidators}}',
            '{{afterValidationHooks}}',
            '{{prepareForValidation}}',
            '{{sanitization}}',
            '{{ruleObjects}}',
        ], [
            $namespace,
            $imports,
            $className,
            $authorization,
            $validationRules,
            $errorMessages,
            $validationAttributes,
            $customValidators,
            $afterValidationHooks,
            $prepareForValidation,
            $sanitization,
            $ruleObjects,
        ], $stub);
    }

    /**
     * Setup authorization method implementation.
     *
     * @return string The authorization method code
     */
    public function setupAuthorizationMethod(): string
    {
        $useGateBasedAuth = $this->options['use_gate_based_auth'] ?? true;

        if ($useGateBasedAuth) {
            return "    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return \Gate::allows('{action}', \$this->route('{model}'));
    }";
        }

        return "    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }";
    }

    /**
     * Generate validation rules for the given table, action and columns.
     *
     * @param string $table The database table name
     * @param string $action The CRUD action (store, update, etc.)
     * @param array $columns The table columns
     * @return string The validation rules code
     */
    public function generateValidationRules(string $table, string $action, array $columns): string
    {
        $rules = [];
        $primaryKey = $this->databaseAnalyzer->getPrimaryKey($table);

        foreach ($columns as $column) {
            // Skip primary key and timestamps
            if ($column['name'] == $primaryKey || in_array($column['name'], ['created_at', 'updated_at', 'deleted_at'])) {
                continue;
            }

            $columnRules = $this->generateColumnRules($column, $action, $table);

            if (!empty($columnRules)) {
                $rules[$column['name']] = $columnRules;
            }
        }

        // Add custom rules from options
        if (isset($this->options['custom_rules']) && is_array($this->options['custom_rules'])) {
            foreach ($this->options['custom_rules'] as $field => $customRule) {
                if (!isset($rules[$field])) {
                    $rules[$field] = [];
                }

                if (is_string($customRule)) {
                    $customRule = explode('|', $customRule);
                }

                $rules[$field] = array_merge($rules[$field], $customRule);
            }
        }

        // Format rules for output
        $formattedRules = "    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
";

        foreach ($rules as $field => $fieldRules) {
            $formattedRules .= "            '{$field}' => [" . PHP_EOL;
            foreach ($fieldRules as $rule) {
                $formattedRules .= "                '{$rule}'," . PHP_EOL;
            }
            $formattedRules .= "            ]," . PHP_EOL;
        }

        $formattedRules .= "        ];
    }";

        return $formattedRules;
    }

    /**
     * Generate rules for a specific column.
     *
     * @param array $column The column definition
     * @param string $action The CRUD action (store, update, etc.)
     * @param string $table The table name
     * @return array The column validation rules
     */
    protected function generateColumnRules(array $column, string $action, string $table): array
    {
        $rules = [];
        $name = $column['name'];
        $type = $column['type'];
        $nullable = !$column['required'];

        // Required rule (not for update actions where fields may be optional)
        if (!$nullable && $action !== 'update') {
            $rules[] = 'required';
        } elseif ($action === 'update') {
            $rules[] = 'sometimes';
        } else {
            $rules[] = 'nullable';
        }

        // Type-based rules
        switch ($type) {
            case 'string':
                $rules[] = 'string';
                if (isset($column['length']) && $column['length'] > 0) {
                    $rules[] = 'max:' . $column['length'];
                }
                break;

            case 'integer':
                $rules[] = 'integer';
                break;

            case 'decimal':
            case 'float':
                $rules[] = 'numeric';
                break;

            case 'boolean':
                $rules[] = 'boolean';
                break;

            case 'date':
                $rules[] = 'date';
                break;

            case 'datetime':
                $rules[] = 'date_format:Y-m-d H:i:s';
                break;

            case 'time':
                $rules[] = 'date_format:H:i:s';
                break;

            case 'email':
                $rules[] = 'email';
                break;

            case 'url':
                $rules[] = 'url';
                break;

            case 'json':
                $rules[] = 'json';
                break;
        }

        // Unique rule for store or conditionally for update
        if (isset($column['unique']) && $column['unique']) {
            if ($action === 'store') {
                $rules[] = 'unique:' . $table . ',' . $name;
            } elseif ($action === 'update') {
                $rules[] = 'unique:' . $table . ',' . $name . ',{$this->route()->parameter("id")}';
            }
        }

        return $rules;
    }

    /**
     * Generate custom error messages for validation rules.
     *
     * @param array $rules The validation rules
     * @return string The custom error messages code
     */
    public function generateCustomErrorMessages(array $rules): string
    {
        if (!($this->options['generate_custom_messages'] ?? false)) {
            return '';
        }

        return "    /**
     * Get custom messages for validator errors.
     *
     * @return array
     */
    public function messages()
    {
        return [
            'required' => 'The :attribute field is required.',
            'unique' => 'The :attribute has already been taken.',
            'email' => 'The :attribute must be a valid email address.',
            // Add custom error messages here
        ];
    }";
    }

    /**
     * Setup custom validators for complex validation.
     *
     * @return string The custom validators code
     */
    public function setupCustomValidators(): string
    {
        if (!($this->options['use_custom_validators'] ?? false)) {
            return '';
        }

        return "    /**
     * Configure the validator instance.
     *
     * @param \Illuminate\Validation\Validator \$validator
     * @return void
     */
    public function withValidator(\$validator)
    {
        \$validator->after(function (\$validator) {
            // Add custom validation logic here
            // Example:
            // if (\$this->somethingElseIsInvalid()) {
            //     \$validator->errors()->add('field', 'Something is wrong with this field!');
            // }
        });
    }";
    }

    /**
     * Generate validation attributes for human-readable field names.
     *
     * @param array $columns The table columns
     * @return string The validation attributes code
     */
    public function generateValidationAttributes(array $columns): string
    {
        if (!($this->options['use_custom_attributes'] ?? false)) {
            return '';
        }

        $attributes = "    /**
     * Get custom attributes for validator errors.
     *
     * @return array
     */
    public function attributes()
    {
        return [
";

        foreach ($columns as $column) {
            $name = $column['name'];
            $label = $this->stringHelper->generateHumanReadableLabel($name);
            $attributes .= "            '{$name}' => '{$label}'," . PHP_EOL;
        }

        $attributes .= "        ];
    }";

        return $attributes;
    }

    /**
     * Setup after validation hooks for special processing.
     *
     * @return string The after validation hooks code
     */
    public function setupAfterValidationHooks(): string
    {
        if (!($this->options['use_after_validation_hooks'] ?? false)) {
            return '';
        }

        return "    /**
     * Handle a passed validation attempt.
     *
     * @return void
     */
    protected function passedValidation()
    {
        // Process the validated data as needed
        // This method is called automatically after successful validation
    }";
    }

    /**
     * Generate prepare for validation method for data preprocessing.
     *
     * @return string The prepare for validation method code
     */
    public function generatePrepareForValidationMethod(): string
    {
        if (!($this->options['use_prepare_validation'] ?? false)) {
            return '';
        }

        return "    /**
     * Prepare the data for validation.
     *
     * @return void
     */
    protected function prepareForValidation()
    {
        // Modify input data before validation happens
        // Example:
        // \$this->merge([
        //     'slug' => Str::slug(\$this->title),
        // ]);
    }";
    }

    /**
     * Setup data sanitization before validation.
     *
     * @return string The sanitization code
     */
    public function setupSanitization(): string
    {
        if (!($this->options['use_sanitization'] ?? false)) {
            return '';
        }

        return "    /**
     * Sanitize input before validation.
     *
     * @return array
     */
    public function validationData()
    {
        \$data = parent::validationData();
        
        // Sanitize data here
        // Example:
        // if (isset(\$data['html_content'])) {
        //     \$data['html_content'] = clean(\$data['html_content']);
        // }
        
        return \$data;
    }";
    }

    /**
     * Generate rule objects for complex validation rules.
     *
     * @param array $complexRules Array of complex rule configurations
     * @return string The rule objects code
     */
    public function generateRuleObjects(array $complexRules): string
    {
        if (empty($complexRules)) {
            return '';
        }

        $ruleCode = "";

        foreach ($complexRules as $ruleName => $ruleConfig) {
            $ruleCode .= "    /**
     * Get the {$ruleName} rule instance.
     *
     * @return \Illuminate\Validation\Rules\Rule
     */
    protected function {$ruleName}()
    {
        return new \Illuminate\Validation\Rules\\{$ruleConfig['type']}({$ruleConfig['params']});
    }
";
        }

        return $ruleCode;
    }

    /**
     * Get required imports for the request class.
     *
     * @param string $action The CRUD action
     * @return string The import statements
     */
    protected function getRequiredImports(string $action): string
    {
        $imports = [
            'Illuminate\Foundation\Http\FormRequest',
        ];

        if ($this->options['use_gate_based_auth'] ?? true) {
            $imports[] = 'Illuminate\Support\Facades\Gate';
        }

        if (($this->options['use_prepare_validation'] ?? false) && str_contains($this->generatePrepareForValidationMethod(), 'Str::slug')) {
            $imports[] = 'Illuminate\Support\Str';
        }

        $imports = array_unique($imports);
        sort($imports);

        $importStatements = '';
        foreach ($imports as $import) {
            $importStatements .= "use {$import};\n";
        }

        return $importStatements;
    }


    /**
     * Set configuration options for the generator.
     *
     * @param array $options Configuration options
     * @return self Returns the generator instance for method chaining
     */
    public function setOptions(array $options): self
    {
        $this->options = array_merge($this->options ?? [], $options);
        return $this;
    }

    /**
     * Get a list of all generated file paths.
     *
     * @return array List of generated file paths
     */
    public function getGeneratedFiles(): array
    {
        return $this->generatedFiles ?? [];
    }

    /**
     * Determine if the generator supports customization.
     *
     * @return bool True if the generator supports customization
     */
    public function supportsCustomization(): bool
    {
        return true;
    }
}
