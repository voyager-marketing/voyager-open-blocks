// The PR title is the squash-merge commit subject, so CI lints the title.
// No `extends`: CI runs this through npx without adding commitlint to package.json.
module.exports = {
	rules: {
		'type-enum': [
			2,
			'always',
			[
				'feat',
				'fix',
				'docs',
				'style',
				'refactor',
				'perf',
				'test',
				'chore',
				'revert',
			],
		],
		'type-case': [ 2, 'always', 'lower-case' ],
		'type-empty': [ 2, 'never' ],
		'subject-empty': [ 2, 'never' ],
		'subject-case': [ 2, 'never', [ 'upper-case' ] ],
		'subject-full-stop': [ 2, 'never', '.' ],
		'header-max-length': [ 2, 'always', 72 ],
	},
};
