<?php

namespace Ogrre\CRUDGenerator\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class CRUDGeneratorCommand extends Command
{
    protected $signature = 'make:crud {name}';
    protected $description = 'Generate CRUD operations for a given model';

    protected Filesystem $files;

    public function __construct(Filesystem $files)
    {
        parent::__construct();
        $this->files = $files;
    }

    public function handle()
    {
        $modelName = $this->argument('name');
        $attributes = $this->askForAttributes();
        $relations = $this->askForRelations();

        $this->generateModel($modelName, $attributes, $relations);
        $this->generateMigration($modelName, $attributes);
        $this->generateController($modelName);
        $this->generateRequests($modelName, $attributes);

        $this->info('CRUD for ' . $modelName . ' generated successfully along with migration.');
    }

    protected function askForAttributes(): array
    {
        $attributes = [];
        while (true) {
            $name = $this->ask('Nom de l\'attribut (laisser vide pour terminer)');

            if (empty($name)) {
                break;
            }

            $type = $this->choice(
                'Type de l\'attribut',
                ['string', 'integer', 'float', 'boolean', 'date', 'datetime', 'text'],
                'string'
            );

            $isNullable = $this->confirm('L\'attribut peut-il être nul ?', false);

            $attributes[$name] = ['type' => $type, 'nullable' => $isNullable];
        }

        return $attributes;
    }

    protected function askForRelations(): array
    {
        $relations = [];
        while (true) {
            if (!$this->confirm('Voulez-vous ajouter une relation ?', true)) {
                break;
            }

            $relationType = $this->choice(
                'Type de relation',
                ['hasOne', 'hasMany', 'belongsTo', 'belongsToMany'],
                'hasOne'
            );

            $relatedModel = $this->ask('Nom du modèle lié');

            $relations[] = ['type' => $relationType, 'model' => $relatedModel];
        }

        return $relations;
    }

    protected function generateModel(string $modelName, array $attributes, array $relations): void
    {
        $modelTemplate = $this->files->get(__DIR__ . '/stubs/model.stub');

        $modelTemplate = str_replace('{{modelName}}', $modelName, $modelTemplate);
        $modelTemplate = str_replace('{{fillableAttributes}}', $this->generateAttributes($attributes), $modelTemplate);
        $modelTemplate = str_replace('{{relations}}', $this->generateRelations($relations), $modelTemplate);

        $this->files->put(app_path("/Models/{$modelName}.php"), $modelTemplate);
    }

    protected function generateAttributes(array $attributes): array
    {
        $properties = '';
        $rules = '';

        foreach ($attributes as $name => $type) {
            $properties .= "\n\tprotected \$$name;";

            $rule = $type === 'string' ? 'required|max:255' : 'required';
            $rules .= "\n\t\t\t'$name' => '$rule',";
        }

        return compact('properties', 'rules');
    }

    protected function generateRelations(array $relations): string
    {
        $relationMethods = '';

        foreach ($relations as $relation) {
            $methodName = Str::camel(Str::plural($relation['model'])); // ajustez selon la convention
            $relationType = $relation['type'];
            $relatedModelClass = 'App\\Models\\' . $relation['model'];

            $relationMethods .= "\n\tpublic function $methodName() {\n";
            $relationMethods .= "\t\treturn \$this->$relationType($relatedModelClass::class);\n";
            $relationMethods .= "\t}\n";
        }

        return $relationMethods;
    }

    protected function generateMigration($modelName, array $attributes): void
    {
        $className = 'Create' . Str::plural(Str::studly($modelName)) . 'Table';
        $tableName = Str::plural(Str::snake($modelName));
        $migrationName = date('Y_m_d_His') . '_create_' . $tableName . '_table.php';
        $migrationPath = database_path('migrations/' . $migrationName);

        $migrationTemplate = Str::replaceArray(
            '{{}}',
            [$className, $tableName, $this->generateMigrationColumns($attributes)],
            $this->files->get(__DIR__ . '/stubs/migration.stub')
        );

        $this->files->put($migrationPath, $migrationTemplate);
    }

    protected function generateMigrationColumns(array $attributes): string
    {
        $columns = '';
        foreach ($attributes as $name => $type) {
            $columns .= "\n\t\t\t\$table->$type('$name');";
        }
        return $columns;
    }

    protected function generateController($modelName): void
    {
        $controllerTemplate = Str::replaceArray(
            '{{}}',
            [$modelName, Str::plural($modelName), Str::singular($modelName)],
            $this->files->get(__DIR__ . '/stubs/controller.stub')
        );

        $this->files->put(app_path("/Http/Controllers/{$modelName}Controller.php"), $controllerTemplate);
    }

    protected function generateRequests($modelName, array $attributes): void
    {
        foreach (['Store', 'Update'] as $type) {
            $className = "{$type}{$modelName}Request";
            $rules = $this->generateRules($attributes);

            $requestTemplate = Str::replaceArray(
                '{{}}',
                [$className, $rules],
                $this->files->get(__DIR__.'/stubs/request.stub')
            );

            $this->files->put(app_path("/Http/Requests/{$className}.php"), $requestTemplate);
        }
    }

    protected function generateRules(array $attributes): string
    {
        $rules = [];
        foreach ($attributes as $name => $details) {
            $rule = $details['nullable'] ? 'nullable' : 'required';

            switch ($details['type']) {
                case 'string':
                    $rule .= '|string|max:255';
                    break;
                case 'integer':
                    $rule .= '|integer';
                    break;
                // Ajoutez ici des cas pour les autres types si nécessaire
            }

            $rules[] = "'$name' => '$rule'";
        }

        return implode(",\n\t\t\t", $rules);
    }

    protected function generateRoutes(string $modelName): void
    {
        $modelNamePlural = Str::plural(Str::kebab($modelName));
        $modelNameSingular = Str::singular($modelNamePlural);

        $routeTemplate = "\n// Routes for $modelName\n";
        $routeTemplate .= "Route::resource('$modelNamePlural', '{$modelName}Controller');\n";

        File::append(base_path('routes/web.php'), $routeTemplate);
    }

}


