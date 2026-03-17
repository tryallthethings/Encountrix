module.exports = {
	extends: ['stylelint-config-standard'],
	ignoreFiles: ['**/*.min.css'],
	rules: {
		// Allow class names with any pattern (WordPress uses various conventions)
		'selector-class-pattern': null,

		// Disable modern CSS syntax conversions for broader browser compatibility
		'color-function-notation': null,        // Keep rgba() syntax
		'alpha-value-notation': null,           // Keep 0.12 instead of 12%
		'media-feature-range-notation': null,   // Keep (max-width: 782px) syntax

		// Allow legacy color formats
		'function-no-unknown': null,
	},
};
