<?php

namespace SocialDept\AtpParity\Commands;

use Illuminate\Console\GeneratorCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'make:atp-mapper')]
class MakeMapperCommand extends GeneratorCommand
{
    protected $name = 'make:atp-mapper';

    protected $description = 'Create a new AT Protocol record mapper class';

    protected $type = 'Mapper';

    protected function getStub(): string
    {
        $customStub = base_path('stubs/atp-mapper.stub');

        if (file_exists($customStub)) {
            return $customStub;
        }

        return __DIR__.'/../../stubs/mapper.stub';
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        $path = config('parity.generators.mapper_path', 'app/AtpMappers');

        // Convert path to namespace (app/AtpMappers -> App\AtpMappers)
        $namespace = str_replace('/', '\\', $path);
        $namespace = preg_replace('/^app\\\\/i', $rootNamespace.'\\', $namespace);

        return $namespace;
    }

    protected function getPath($name): string
    {
        $name = str_replace('\\', '/', str_replace($this->rootNamespace(), '', $name));
        $path = config('parity.generators.mapper_path', 'app/AtpMappers');

        return base_path($path).'/'.trim($name, '/').'.php';
    }

    protected function buildClass($name): string
    {
        $stub = parent::buildClass($name);

        $model = $this->option('model');
        $record = $this->option('record');

        if ($model) {
            $stub = str_replace(['DummyModel', '{{ model }}'], $model, $stub);
        }

        if ($record) {
            $stub = str_replace(['DummyRecord', '{{ record }}'], $record, $stub);
        }

        return $stub;
    }

    protected function getOptions(): array
    {
        return [
            ['model', 'm', InputOption::VALUE_OPTIONAL, 'The model class the mapper is for'],
            ['record', 'r', InputOption::VALUE_OPTIONAL, 'The record class the mapper handles'],
        ];
    }
}
