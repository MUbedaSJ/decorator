;(function()
{
	// CommonJS
	// author William Sullivan : https://wordpress.org/plugins/twig-highlighter/
	SyntaxHighlighter = SyntaxHighlighter || (typeof require !== 'undefined'? require('shCore').SyntaxHighlighter : null);

	function Brush()
	{
		var filters = 'abs batch capitalize convert_encoding date ' +
					  'date_modify default escape first format join ' +
					  'json_encode keys last length lower merge nl2br ' +
					  'number_format raw replace reverse round slice ' +
					  'sort split striptags title trim upper url_encode ' +
					  'index index0';

		var tags = 'autoescape block do embed extends filter flush for ' + 
				   'from if import include macro sandbox set spaceless use ' + 
				   'verbatim else elseif endif loop';

		var functions = 'attribute block constant cycle date dump include ' +
						'max min parent random range source ' +
						'template_from_string';

		var tests = 'constant defined divisibleby empty even iterable ' +
					'null odd sameas';

		var operators = 'in is and or not b-and b-or b-xor ' +
						'starts ends with matches';


		this.regexList = [
				{ regex: SyntaxHighlighter.regexLib.multiLineDoubleQuotedString, css: 'string' },
				{ regex: /({#)(.*)(#})/g,										 css: 'comments' }, //comments
				{ regex: /({%)|(%})/g,											 css: 'keyword' }, // logic tags
				{ regex: /({{)|(}})/g,											 css: 'keyword' }, // output tags
				{ regex: /\|/,													 css: 'keyword' },
				{ regex: /\/\/.*/,												 css: 'color1' }, //comments
				{ regex: new RegExp(this.getKeywords(filters), 'gmi'),			 css: 'functions' }, // keywords
				{ regex: new RegExp(this.getKeywords(tags), 'gmi'),				 css: 'keyword' }, // keywords
				{ regex: new RegExp(this.getKeywords(functions), 'gmi'),		 css: 'keyword' }, // keywords
				{ regex: new RegExp(this.getKeywords(tests), 'gmi'),			 css: 'keyword' }, // keywords
				{ regex: new RegExp(this.getKeywords(operators), 'gmi'),		 css: 'keyword' }
		];

		this.forHtmlScript({left:/({)(%|{)/g,right:/(%|})(})/g,eof:!0});
	};

	Brush.prototype	= new SyntaxHighlighter.Highlighter();
	Brush.aliases	= ['twig'];

	SyntaxHighlighter.brushes.Twig = Brush;

	// CommonJS
	typeof(exports) != 'undefined' ? exports.Brush = Brush : null;
})();

