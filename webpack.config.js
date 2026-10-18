const path = require('path')
const webpack = require('webpack')
const { VueLoaderPlugin } = require('vue-loader')

// Read package.json for version
const packageJson = require('./package.json')

module.exports = {
  entry: {
    admin: path.join(__dirname, 'src', 'admin.js'),
    user: path.join(__dirname, 'src', 'user.js'),
    filesplugin: path.join(__dirname, 'src', 'filesplugin', 'filesplugin-main.js'),
    'metavox-init': path.join(__dirname, 'src', 'filesplugin', 'init.js'),
    'metavox-flow': path.join(__dirname, 'src', 'flow', 'main.js'),
    'metavox-search': path.join(__dirname, 'src', 'search', 'search-main.js'),
    'metavox-embed': path.join(__dirname, 'src', 'embed', 'embed.js'),
  },
  output: {
    path: path.resolve(__dirname, 'js'),
    filename: '[name].js',
    // Nextcloud versions the entry scripts itself; the chunks they load are
    // ours to version. Without it a browser keeps an old chunk for six months
    // (max-age) after an update, and new code runs against it.
    chunkFilename: '[name].js?v=[contenthash:8]',
    // Chunks no build makes any more are not left behind.
    clean: true,
  },
  module: {
    rules: [
      {
        test: /\.vue$/,
        loader: 'vue-loader',
        options: {
          // Template comments (our <!-- TRANSLATORS --> hints among them) stay out
          // of the shipped bundle, and can never become a component's slot content
          // (NcActionButton takes its first slot node as its label).
          compilerOptions: { comments: false },
        },
      },
      {
        test: /\.js$/,
        loader: 'babel-loader',
        exclude: /node_modules/
      },
      {
        test: /\.css$/,
        use: ['style-loader', 'css-loader']
      },
      {
        test: /\.scss$/,
        use: [
          'style-loader',
          'css-loader',
          {
            loader: 'sass-loader',
            options: {
              api: 'modern',
              sassOptions: {
                silenceDeprecations: ['legacy-js-api']
              }
            }
          }
        ]
      },
      {
        test: /\.svg$/,
        type: 'asset/source'
      }
    ]
  },
  plugins: [
    new VueLoaderPlugin(),
    new webpack.DefinePlugin({
      appName: JSON.stringify('metavox'),
      appVersion: JSON.stringify(packageJson.version),
      __VUE_OPTIONS_API__: true,
      __VUE_PROD_DEVTOOLS__: false,
      __VUE_PROD_HYDRATION_MISMATCH_DETAILS__: false,
    }),
  ],
  resolve: {
    // No alias for vue: its runtime build, as Nextcloud's own config uses.
    // Templates are compiled at build time; the browser needs no compiler.
    extensions: ['.js', '.vue'],
    fallback: {
      "path": false,
      "https": false,
      "stream": false,
      "http": false,
      "url": false,
      "zlib": false,
      "assert": false,
      "util": false,
      "buffer": false,
      "string_decoder": false
    }
  },
  // No size warnings: Nextcloud loads our bundles itself.
  performance: { hints: false },
  devtool: false,
  // A build reuses the last one's work (node_modules/.cache). Not in CI,
  // where every run starts clean.
  cache: process.env.CI ? false : {
    type: 'filesystem',
    buildDependencies: {
      config: [__filename, path.join(__dirname, 'babel.config.js'), path.join(__dirname, 'package.json')],
    },
  },
}