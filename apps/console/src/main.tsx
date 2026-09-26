import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'

import { ConfirmProvider } from '@dialysis/ui'

import { App } from './App'
import './styles.css'

const container = document.getElementById('root')

if (container === null) {
  throw new Error('#root is missing from index.html')
}

createRoot(container).render(
  <StrictMode>
    <ConfirmProvider>
      <App />
    </ConfirmProvider>
  </StrictMode>,
)
