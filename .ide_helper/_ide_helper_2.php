<?php

namespace Illuminate\Support{
    class Fluent {
        /**
         * @return Fluent
         */
        public function index(){return $this;}

        /**
         * @return Fluent
         */
        public function unique(){return $this;}

        /**
         * Place the column "after" another column (MySQL Only)
         * @param $column
         * @return Fluent
         */
        public function after($column){return $this;}

        /**
         * Add a comment to a column
         * @param $comment
         * @return Fluent
         */
        public function comment($comment){return $this;}

        /**
         * Specify a "default" value for the column
         * @param $value
         * @return Fluent
         */
        public function default($value){return $this;}

        /**
         * Place the column "first" in the table (MySQL Only)
         * @return Fluent
         */
        public function first(){return $this;}

        /**
         * Allow NULL values to be inserted into the column
         * @return Fluent
         */
        public function nullable(){return $this;}

        /**
         * Create a stored generated column (MySQL Only)
         * @param $expression
         * @return Fluent
         */
        public function storedAs($expression){return $this;}

        /**
         * Set integer columns to UNSIGNED
         */
        public function unsigned(){return $this;}

        /**
         * Create a virtual generated column (MySQL Only)
         * @param $expression
         * @return Fluent
         */
        public function virtualAs($expression){return $this;}

        /**
         * The change method allows you to modify some existing column types to a new type or modify the column's attributes.
         */
        public function change(){return $this;}


    }
}
namespace  {
    class Eloquent_My extends \Illuminate\Database\Eloquent\Model {

        /**
         * Find a model by its primary key or throw an exception.
         *
         * @param mixed $id
         * @param array $columns
         * @return \Illuminate\Database\Eloquent\Model|\Illuminate\Database\Eloquent\Collection|static[]|static|null
         * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
         * @static
         */
        public static function findOrFail($id, $columns = array())
        {
            return \Illuminate\Database\Eloquent\Builder::findOrFail($id, $columns);
        }
    }
}

namespace Illuminate\Database\Query{
    class Builder{
        /**
         * Execute the query and get the first result or throw an exception.
         *
         * @param array $columns
         * @return \Illuminate\Database\Eloquent\Model|static
         * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
         * @static
         */
        public static function firstOrFail($columns = array())
        {
            return \Illuminate\Database\Eloquent\Builder::firstOrFail($columns);
        }
    }
}