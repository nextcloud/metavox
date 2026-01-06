const path = require('path')
const { VueLoaderPlugin } = require('vue-loader')

module.exports = {
  entry: {
    admin: path.join(__dirname, 'src', 'admin.js'),
    user: path.join(__dirname, 'src', 'user.js'),
    filesplugin: path.join(__dirname, 'src', 'filesplugin', 'filesplugin-main.js'),
    'metavox-flow': path.join(__dirname, 'src', 'flow', 'main.js'),
  },
  output: {
    path: path.resolve(__dirname, 'js'),
    filename: '[name].js'
  },
  module: {
    rules: [
      {
        test: /\.vue$/,
        loader: 'vue-loader'
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
    new VueLoaderPlugin()
  ],
  resolve: {
    extensions: ['.js', '.vue'],
    alias: {
      vue$: 'vue/dist/vue.esm.js'
    },
    fallback: {
      "path": false,
      "https": false,
      "stream": false,
      "http": false,
      "url": false,
      "zlib": false,
      "assert": false,
      "util": false,
      "buffer": require.resolve("buffer/"),
      "string_decoder": false
    }
  },
  performance: {
    hints: false,
    maxAssetSize: 512000,
    maxEntrypointSize: 512000
  },
  mode: 'development'
}