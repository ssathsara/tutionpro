import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import { setUnauthorizedHandler } from './api/client'
import { useAuthStore } from './stores/auth'
import { applyTheme } from './composables/useTheme'
import './style.css'

applyTheme()

const app = createApp(App)
app.use(createPinia())
app.use(router)

setUnauthorizedHandler(() => {
  const auth = useAuthStore()
  if (auth.token) {
    auth.clear()
    router.push('/login')
  }
})

app.mount('#app')
