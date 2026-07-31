package main

import "database/sql"

func nullableID(id int64) sql.NullInt64 {
	if id == 0 {
		return sql.NullInt64{}
	}

	return sql.NullInt64{Int64: id, Valid: true}
}
