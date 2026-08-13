/** @type {import('tailwindcss').Config} */
module.exports = {
  darkMode: 'selector',
  content: [
    "./assets/**/*.js",
    "./templates/**/*.html.twig",
  ],
  theme: {
    fontFamily:{
        'body':['Inter','sans-serif'],
        'sans':['Inter','sans-serif']
      },
    extend: {
      colors:{
        'primary': {
            DEFAULT:'#0769a1',
            light: '#1679b1',
        },
      },
        spacing:{
            '55vw':'55vw'
        },
        container:{
            center:true,
            padding:{
                DEFAULT: '1rem',
                sm:'2rem',
            },
            screens: {
                sm: '600px',
                md: '728px',
                lg: '984px',
                xl: '1240px',
                '2xl': '1240px',
            },
        }
    },
  },
  plugins: [
      //require('@tailwindcss/forms'),
      //require('flowbite/plugin')
  ],
}
