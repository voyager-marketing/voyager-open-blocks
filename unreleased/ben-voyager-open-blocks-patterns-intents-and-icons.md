type: fix
A page no longer reads the pack's pattern files and icon file on every request: patterns and icons ship as PHP arrays, which OPcache keeps in memory.
