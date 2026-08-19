package main

import "database/sql"

func nullableID(id int64) sql.NullInt64 {
	if id == 0 {
		return sql.NullInt64{}
	}

	return sql.NullInt64{Int64: id, Valid: true}
}

// boolFlag wraps a known true/false into a NullBool that is always Valid, so a
// recorded false ("this DNS lookup was not blocked") is stored as 0, distinct
// from the SQL NULL left on rows the flag never applied to (HTTP requests).
func boolFlag(b bool) sql.NullBool {
	return sql.NullBool{Bool: b, Valid: true}
}
