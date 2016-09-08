<?php
/**
 * Adds a new Chado table to the semantic web support for Chado.
 *
 * Newly added tables (i.e. custom tables) need to be integrated into the
 * semantic web infrastructure.  After a new table is created and added to
 * the Chado schema, this function should be called to indicate that the
 * table should be included in the semantic web. No associations are made for
 * the columns. The associations should be added using the
 * tripal_associate_chado_semweb_term() function.
 *
 * If the table has already been added previously then this function does
 * nothing. It will not overwrite existing assocations.
 *
 * Temporary tables (e.g. Tripal tables that begin with 'tripal_' and end with
 * '_temp', are not supported.
 *
 * @param $chado_table
 *   The name of the Chado table.
 */
function tripal_add_chado_semweb_table($chado_table) {

  // Don't include the tripal temp tables
  if (preg_match('/tripal_.+_temp/', $chado_table)) {
    return;
  }

  // Get the table's schema and add all of it's fields if they aren't
  // already there.
  $schema = chado_get_schema($chado_table);
  foreach ($schema['fields'] as $chado_column => $details) {

    // If the record already exists don't overwrite it
    $record = db_select('chado_semweb', 'CS')
      ->fields('CS', array('chado_semweb_id'))
      ->condition('CS.chado_table', $chado_table)
      ->condition('CS.chado_column', $chado_column)
      ->execute()
      ->fetchField();
    if (!$record) {
      $record = array(
        'chado_table' => $chado_table,
        'chado_column' => $chado_column,
      );
      drupal_write_record('chado_semweb', $record);
    }
  }
}
/**
 * Associates a controlled vocabulary term with a field in a Chado table.
 *
 * For sharing of data via the semantic web we need to associate a
 * term from a controlled vocabulary with every column of every table in Chado.
 *
 * Temporary tables (e.g. Tripal tables that begin with 'tripal_' and end with
 * '_temp', are not supported.
 *
 * @param $chado_table
 *   The name of the table in Chado. This argument is optional. If left empty
 *   or set to NULL then all fields in all Chado tables with that have the
 *   $column_name will be associated with the provided $term.
 * @param $chado_column
 *   The column name in the Chado table to which the term should be associated.
 * @param $term
 *   A cvterm object as returned by chado_generate_var().
 * @param $update
 *   Set to TRUE if the association should be updated to use the new term
 *   if a term is already associated with the table and column.  Default is
 *   FALSE.  If not TRUE and a term is already associated, then no change
 *   occurs.
 *
 * @return boolean
 *   Returns TRUE if the association was made successfully and FALSE otherwise.
 */
function tripal_associate_chado_semweb_term($chado_table, $chado_column, $term,
    $update = FALSE) {

  // Check for required arguments.
  if (!$chado_column) {
    tripal_set_message('Please provide the $chado_column argument.', TRIPAL_ERROR);
    return FALSE;
  }
  if (!$term) {
    tripal_set_message('Please provide the $term argument.', TRIPAL_ERROR);
    return FALSE;
  }

  // Make sure the field is a real field for the table.
  if ($chado_table) {
    $schema = chado_get_schema($chado_table);
    if (!$schema) {
      tripal_set_message('The $chado_table is not a known table in Chado.', TRIPAL_ERROR);
      return FALSE;
    }
    if (!array_key_exists($chado_column, $schema['fields'])) {
      tripal_set_message('The $chado_column is not a known column in the $chado_table.', TRIPAL_ERROR);
      return FALSE;
    }
  }

  // First check to see if a valid record exists that matches the table and
  // column indicated. If it doesn't then insert the record.
  $query = db_select('chado_semweb', 'CS')
    ->fields('CS', array('chado_semweb_id'))
    ->condition('chado_column', $chado_column);
  if ($chado_table) {
    $query->condition('chado_table', $chado_table);
  }
  $query->range(0,1);
  $id = $query->execute()->fetchField();
  if (!$id) {

    // If no $chado_table record is provided then return FALSE as we can't
    // insert a record without a table.
    if (!$chado_table) {
      tripal_set_message('The provided $chado_column has no match for any
          table currently known. This could be because the table has not yet
          been added to the semantic web management. Please provide the
          $chado_table.', TRIPAL_ERROR);
      return FALSE;
    }

    // Insert the record.
    $id = db_insert('chado_semweb')
    ->fields(array(
      'chado_table' => $chado_table,
      'chado_column' => $chado_column,
      'cvterm_id' => $term->cvterm_id,
    ));
    if ($id) {
      return TRUE;
    }
    else {
      tripal_set_message('Failure associating term.', TRIPAL_ERROR);
      return FALSE;
    }
  }

  // If the $chado_table argument is empty or NULL then the term applies to
  // all fields of the specified name.
  $update = db_update('chado_semweb')
  ->fields(array(
    'cvterm_id' => $term->cvterm_id
  ))
  ->condition('chado_column', $chado_column);
  if ($chado_table) {
    $update->condition('chado_table', $chado_table);
  }
  if (!$update) {
    $update->condition('cvterm_id', NULL);
  }
  $num_updated = $update->execute();
  if (!$num_updated) {
    tripal_set_message('Failure associating term.', TRIPAL_ERROR);
    return FALSE;
  }

  return TRUE;
}

/**
 * Retrieves the term that maps to the given Chado table and field.
 *
 * @param $chado_table
 *   The name of the Chado table.
 * @param $chado_column
 *   The name of the Chado field.
 * @param $options
 *   An associative array of one or more of the following keys:
 *     -return_object:  Set to TRUE to return the cvterm object rather than
 *      the string version of the term.
 *
 * @return
 *   Returns a string-based representation of the term (e.g. SO:0000704). If
 *   the 'return_object' options is provided then a cvterm object is returned.
 *   returns NULL if no term is mapped to the table and column.
 */
function tripal_get_chado_semweb_term($chado_table, $chado_column, $options = array()) {
  $cvterm_id = db_select('chado_semweb', 'CS')
    ->fields('CS', array('cvterm_id'))
    ->condition('chado_column', $chado_column)
    ->condition('chado_table', $chado_table)
    ->execute()
    ->fetchField();

  if ($cvterm_id) {
    $cvterm = tripal_get_cvterm(array('cvterm_id' => $cvterm_id));
    if (array_key_exists('return_object', $options)) {
      return $cvterm;
    }

    return $cvterm->dbxref_id->db_id->name . ':' . $cvterm->dbxref_id->accession;
  }
}