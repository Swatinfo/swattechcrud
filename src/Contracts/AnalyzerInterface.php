<?php

namespace SwatTech\Crud\Contracts;

/**
 * Interface AnalyzerInterface
 * 
 * This interface defines standard methods that all database analyzers
 * must implement. Analyzers are responsible for examining database tables
 * and extracting structural information needed for code generation.
 *
 * @package SwatTech\Crud\Contracts
 */
interface AnalyzerInterface
{
    /**
     * Analyze the specified database table structure.
     *
     * This method initiates the analysis process on a specified table.
     * It should extract column information, indexes, constraints, and 
     * relationship data that can be used for code generation.
     *
     * @param string $table The name of the database table to analyze
     * @return self Returns the analyzer instance for method chaining
     */
    public function analyze(string $table);

    /**
     * Retrieve the analysis results.
     *
     * This method returns the analysis data collected by the analyze() method.
     * Results should include all information needed for generating models,
     * migrations, and related code.
     *
     * @return array The complete results of the analysis
     */
    public function getResults(): array;

    /**
     * Set the database connection to use for analysis.
     *
     * This allows analyzers to work with different database connections
     * specified in the Laravel configuration.
     *
     * @param string $connection The name of the database connection
     * @return self Returns the analyzer instance for method chaining
     */
    public function setConnection(string $connection);

    /**
     * Get the database schema information.
     *
     * This method provides access to the schema information used
     * by the analyzer, which may include a reference to the Laravel Schema facade
     * or a custom schema helper.
     *
     * @return mixed The schema information or helper
     */
    public function getSchema();

    /**
     * Get the current database name.
     *
     * This returns the name of the database being analyzed, useful for
     * cross-database relationship analysis.
     *
     * @return string The database name
     */
    public function getDatabaseName(): string;

    /**
     * Get a list of relationship types supported by this analyzer.
     *
     * Each analyzer may support different types of relationships like
     * belongsTo, hasMany, hasOne, etc. This method returns which ones
     * are supported by the specific analyzer implementation.
     *
     * @return array<string> List of supported relationship types
     */
    public function supportedRelationships(): array;
}
